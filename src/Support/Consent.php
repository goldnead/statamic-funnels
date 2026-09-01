<?php

namespace Goldnead\StatamicFunnels\Support;

use Closure;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Support\PaymentDetails;

/**
 * Die Einwilligung am Bestellknopf, und was davon an die Zahlung geht.
 *
 * § 356 Abs. 5 BGB: das Widerrufsrecht erlischt bei digitalen Inhalten erst,
 * wenn der Verbraucher **ausdruecklich zugestimmt** hat und seine Kenntnis
 * bestaetigt. Bisher wurde `confirmed` validiert und verworfen — kein
 * Zeitpunkt, keine Textfassung. Ein Haken ohne Fassung ist wertlos, sobald
 * sich der Text aendert.
 *
 * Deshalb drei Dinge:
 *
 * 1. **Der Wortlaut kommt vom Angebot**, wenn es einen hat
 *    (`Offer::withdrawalTerms()`), sonst aus der Sprachdatei. Mit Fassung.
 * 2. **Die Seite schickt den gezeigten Wortlaut zurueck**, und der Server
 *    vergleicht ihn mit dem erwarteten. Weicht er ab, war die Seite veraltet
 *    oder manipuliert — dann wird nicht bestellt, sondern neu geladen.
 * 3. **Zeitpunkt und Wortlaut gehen in die Zahlung hinein**, nicht hinterher.
 *
 * Die Nachbar-Addons sind optional in ihrer Fassung: `withdrawalTerms()`,
 * `accessWindow()` und die Spalten `consent_at`/`consent_text` werden nur
 * benutzt, wenn es sie gibt. Ein aelteres payments bekommt die Einwilligung
 * in `meta['consent']`, ein aelteres offers den Text aus der Sprachdatei.
 */
class Consent
{
    protected static ?Closure $termsResolver = null;

    protected static ?Closure $accessResolver = null;

    /**
     * Fuer Tests, und fuer eine Site, die die Konditionen woanders fuehrt.
     *
     * @param  (Closure(Offer): (array<string, mixed>|null))|null  $resolver
     */
    public static function resolveTermsUsing(?Closure $resolver): void
    {
        static::$termsResolver = $resolver;
    }

    /**
     * @param  (Closure(Offer): (array<string, mixed>|null))|null  $resolver
     */
    public static function resolveAccessUsing(?Closure $resolver): void
    {
        static::$accessResolver = $resolver;
    }

    /**
     * Die Widerrufskonditionen des Angebots, falls es welche fuehrt.
     *
     * @return array{days?: int, text?: string, waiver_text?: string, checkbox_required?: bool, b2b_text?: string, version?: string}|null
     */
    public static function terms(Offer $offer): ?array
    {
        if (static::$termsResolver) {
            $terms = (static::$termsResolver)($offer);

            return is_array($terms) && $terms !== [] ? $terms : null;
        }

        $terms = Sibling::call($offer, 'withdrawalTerms');

        return is_array($terms) && $terms !== [] ? $terms : null;
    }

    /**
     * Zugangsbeginn und -dauer, falls das Angebot sie setzt.
     *
     * @return array{starts_at?: string|null, days?: int|null}|null
     */
    public static function accessWindow(Offer $offer): ?array
    {
        $window = static::$accessResolver
            ? (static::$accessResolver)($offer)
            : Sibling::call($offer, 'accessWindow');

        if (! is_array($window) || $window === []) {
            return null;
        }

        $startsAt = $window['starts_at'] ?? null;

        return array_filter([
            'starts_at' => $startsAt instanceof \DateTimeInterface ? $startsAt->format(DATE_ATOM) : ($startsAt !== null ? (string) $startsAt : null),
            'days' => isset($window['days']) ? (int) $window['days'] : null,
        ], fn ($value) => $value !== null);
    }

    /** Ob dieser Besuch als Unternehmer kauft: eine USt-IdNr in den Rechnungsangaben. */
    public static function isB2b(?FunnelVisit $visit): bool
    {
        if ($visit === null) {
            return false;
        }

        $billing = (array) (($visit->meta ?? [])['billing'] ?? []);

        return trim((string) ($billing['vat_id'] ?? '')) !== '';
    }

    /**
     * Der Wortlaut, dem zugestimmt wird — mit Fassung in eckigen Klammern.
     *
     * Die Fassung steht **im** Text, nicht daneben: so ist der Vergleich mit
     * dem, was die Seite zurueckschickt, ein Stringvergleich, und die Zeile in
     * der Zahlung sagt aus sich heraus, welche Fassung galt.
     *
     * @param  array<string, mixed>|null  $terms
     */
    public static function text(?array $terms, bool $b2b = false): string
    {
        if ($terms === null) {
            return (string) __('statamic-funnels::messages.order_confirmation');
        }

        $text = $b2b && trim((string) ($terms['b2b_text'] ?? '')) !== ''
            ? (string) $terms['b2b_text']
            : (string) ($terms['waiver_text'] ?? __('statamic-funnels::messages.order_confirmation'));

        $version = trim((string) ($terms['version'] ?? ''));

        return $version === '' ? $text : $text.' ['.$version.']';
    }

    /**
     * Ob der Haken Pflicht ist. Ohne Konditionen am Angebot: ja, wie bisher.
     *
     * @param  array<string, mixed>|null  $terms
     */
    public static function checkboxRequired(?array $terms): bool
    {
        return $terms === null || (bool) ($terms['checkbox_required'] ?? true);
    }

    /**
     * Was die Kassenseite braucht.
     *
     * @return array<string, mixed>
     */
    public static function forTemplate(Offer $offer, ?FunnelVisit $visit): array
    {
        $terms = self::terms($offer);
        $b2b = self::isB2b($visit);

        return [
            // Die Kurzbelehrung ueber dem Knopf. Leer, wenn das Angebot keine
            // fuehrt — dann steht da nur der Satz am Haken, wie bisher.
            'text' => trim((string) ($terms['text'] ?? '')),
            'days' => isset($terms['days']) ? (int) $terms['days'] : null,
            'checkbox_required' => self::checkboxRequired($terms),
            'version' => trim((string) ($terms['version'] ?? '')),
            'b2b' => $b2b,
            // Der Wortlaut am Haken und zugleich der Wert des versteckten Felds.
            'consent_text' => self::text($terms, $b2b),
        ];
    }

    /**
     * Was in die Zahlung hinein geht, in der Form, die `PaymentDetails` nimmt.
     *
     * `consent_at` und `consent_text` als Spalten, wenn das installierte
     * payments sie kennt; sonst unter `meta['consent']`, damit der Beleg
     * nicht verloren geht, nur weil ein Addon einen Stand zurueckliegt. Die
     * Konditionen und das Zugangsfenster gehen immer in `meta`, das ist frei.
     *
     * @return array<string, mixed>
     */
    public static function details(Offer $offer, FunnelVisit $visit, string $consentText): array
    {
        $now = now();
        $terms = self::terms($offer);
        $access = self::accessWindow($offer);

        $meta = array_filter([
            'withdrawal' => $terms,
            'access' => $access,
        ]);

        $details = [];

        if (self::paymentsAllows('consent_at') && self::paymentsAllows('consent_text')) {
            $details['consent_at'] = $now;
            $details['consent_text'] = $consentText;
        } else {
            $meta['consent'] = ['at' => $now->format(DATE_ATOM), 'text' => $consentText];
        }

        if ($meta !== []) {
            $details['meta'] = $meta;
        }

        return $details;
    }

    /** Ob das installierte payments diesen Schluessel einer Zahlung mitgeben laesst. */
    protected static function paymentsAllows(string $key): bool
    {
        if (! defined(PaymentDetails::class.'::ALLOWED')) {
            return false;
        }

        return in_array($key, (array) constant(PaymentDetails::class.'::ALLOWED'), true);
    }
}
