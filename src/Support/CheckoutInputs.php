<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Offers;
use Goldnead\StatamicOffers\Support\Basket;
use Illuminate\Http\Request;
use Statamic\Facades\Dictionary;
use Throwable;

/**
 * Was die Kasse ausser Haekchen und Zahlweise noch einsammelt.
 *
 * Seit statamic-offers 1.12 sind es drei Dinge: der Gutschein aus dem Link,
 * der frei gewaehlte Betrag und das Land, wenn ein Angebot eine Laenderregel
 * hat. Alle drei gibt es nur in einem offers, das sie kennt; gegen ein
 * aelteres bleibt die Kasse, was sie war. Deshalb laeuft jeder Zugriff auf die
 * neuen Methoden ueber {@see Sibling}.
 *
 * **Vorbelegt heisst nicht eingeloest.** Was hier im Feld landet, prueft und
 * verbraucht erst der Korb, gegen dieselbe Tabelle.
 */
class CheckoutInputs
{
    /** Der Code aus dem Link, unter diesem Schluessel am Besuch. */
    public const LINK = 'coupon_link';

    /** Ein Code, der ueber den Kauf hinaus fuer den ganzen Lauf gilt. */
    public const CARRY = 'coupon_carry';

    /** Kennt das installierte offers Coupon-Link, frei gewaehlten Betrag und Laenderregel? */
    public static function supported(): bool
    {
        return Sibling::has(Offers::class, 'couponFromRequest')
            && Sibling::has(Offer::class, 'isAvailableIn');
    }

    public static function couponParameter(): string
    {
        $name = Sibling::call(Offers::class, 'couponParameter');

        return is_string($name) && $name !== '' ? $name : 'coupon';
    }

    /**
     * Einen Code aus der Adresse am Besuch festhalten.
     *
     * Der Flyer zeigt auf den Funnel, nicht auf die Kasse: zwischen Link und
     * Code-Feld liegen Schritte ohne den Parameter. Gemerkt wird nur ein Code,
     * den es gibt und der gerade gilt; ob er zum Angebot passt, entscheidet
     * erst die Kasse, die das Angebot kennt.
     */
    public static function rememberLinkCoupon(Request $request, FunnelVisit $visit): void
    {
        if (! self::supported()) {
            return;
        }

        $code = $request->query(self::couponParameter());

        if (! is_string($code) || trim($code) === '') {
            return;
        }

        $coupon = Coupon::findByCode($code);

        if (! $coupon || ! $coupon->isLive()) {
            return;
        }

        $meta = $visit->meta ?? [];

        if (($meta[self::LINK] ?? null) === $coupon->code) {
            return;
        }

        $meta[self::LINK] = $coupon->code;
        $visit->forceFill(['meta' => $meta])->save();
    }

    /**
     * Was im Code-Feld steht, wenn die Seite aufgeht.
     *
     * Reihenfolge: der Parameter dieser Anfrage, der gemerkte Link-Code, ein
     * Code, der seit einem frueheren Kauf in diesem Lauf fuer alle weiteren
     * Angebote gilt. Je nur, wenn er fuer **dieses** Angebot gilt.
     */
    public static function prefilledCoupon(Request $request, ?FunnelVisit $visit, Offer $offer): ?string
    {
        if (! self::supported()) {
            return null;
        }

        $ausDerAdresse = Sibling::call(Offers::class, 'couponFromRequest', [$request, $offer]);

        if ($ausDerAdresse instanceof Coupon) {
            return $ausDerAdresse->code;
        }

        $meta = $visit->meta ?? [];

        foreach ([self::LINK, self::CARRY] as $key) {
            $code = $meta[$key] ?? null;
            $coupon = is_string($code) ? Coupon::findByCode($code) : null;

            if ($coupon && $coupon->isLive() && $coupon->appliesTo($offer)) {
                return $coupon->code;
            }
        }

        return null;
    }

    /**
     * Der Code, den die Kasse einloest, wenn das Feld leer ankommt.
     *
     * Nur der funnelweite aus einem frueheren Kauf. Ein Link-Code, den die
     * Kaeuferin aus dem Feld geloescht hat, soll geloescht bleiben.
     */
    public static function carriedCoupon(?FunnelVisit $visit): ?string
    {
        $code = ($visit->meta ?? [])[self::CARRY] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Warum ein getippter Code hier nicht gilt, als Satz fuer die Kaeuferin, oder null.
     */
    public static function couponRefusal(?string $code, Offer $offer): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $coupon = Coupon::findByCode($code);

        $key = match (true) {
            $coupon === null => 'coupon_unknown',
            ! $coupon->isLive() => 'coupon_not_live',
            ! $coupon->appliesTo($offer) => 'coupon_not_for_offer',
            default => null,
        };

        return $key === null ? null : (string) __('statamic-funnels::messages.'.$key);
    }

    /** Den eingeloesten Code fuer die weiteren Angebote des Laufs merken, wenn er das darf. */
    public static function carry(FunnelVisit $visit, ?Coupon $coupon): void
    {
        if (! $coupon || Sibling::call($coupon, 'coversFollowUps') !== true) {
            return;
        }

        $meta = $visit->meta ?? [];
        $meta[self::CARRY] = $coupon->code;
        $visit->forceFill(['meta' => $meta])->save();
    }

    // ---------------------------------------------------- Zahl, was du willst

    public static function isPayWhatYouWant(Offer $offer): bool
    {
        return Sibling::call($offer, 'isPayWhatYouWant') === true;
    }

    /**
     * Die Grenzen und der Vorschlag fuer das Betragsfeld, oder null.
     *
     * Zwei Formen je Wert, wie beim Preis: der Punkt fuer das Feld (ein
     * `number`-Feld versteht kein Komma), die deutsche Schreibweise zum Lesen.
     *
     * @return array<string, mixed>|null
     */
    public static function payWhatYouWant(Offer $offer): ?array
    {
        if (! self::isPayWhatYouWant($offer)) {
            return null;
        }

        $min = (int) Sibling::call($offer, 'pwywMinCent');
        $vorschlag = (int) Sibling::call($offer, 'pwywSuggestedCent');
        $max = (int) Sibling::call($offer, 'pwywMaxCent');

        return [
            'min' => self::plain($min),
            'max' => self::plain($max),
            'suggested' => number_format($vorschlag / 100, 2, '.', ''),
            // Was im Feld steht: nach einem Fehler das Getippte, sonst der Vorschlag.
            'value' => is_scalar(old('amount')) && (string) old('amount') !== ''
                ? (string) old('amount')
                : number_format($vorschlag / 100, 2, '.', ''),
            'min_local' => Offer::localise($min),
            'max_local' => Offer::localise($max),
            'suggested_local' => Offer::localise($vorschlag),
            'currency' => $offer->currency(),
        ];
    }

    public static function acceptsAmount(Offer $offer, int $cent): bool
    {
        return Sibling::call($offer, 'acceptsAmount', [$cent]) === true;
    }

    /** Der Satz fuer einen Betrag ausserhalb der Grenzen, mit den Grenzen. */
    public static function amountRangeMessage(Offer $offer): string
    {
        return (string) __('statamic-funnels::messages.amount_out_of_range', [
            'min' => Offer::localise((int) Sibling::call($offer, 'pwywMinCent')).' '.$offer->currency(),
            'max' => Offer::localise((int) Sibling::call($offer, 'pwywMaxCent')).' '.$offer->currency(),
        ]);
    }

    /**
     * Den Korb bauen, mit Betrag und Land, wo das installierte offers sie kennt.
     *
     * Ueber `call_user_func_array` mit benannten Argumenten, damit dieselbe
     * Zeile gegen offers 1.11 (ohne die beiden) und 1.12 laeuft.
     *
     * @param  list<string>  $bumps
     */
    public static function basket(Offer $offer, array $bumps, ?string $code, ?string $option, ?int $amountCent, ?string $country): Basket
    {
        $args = [$offer, $bumps, $code, $option];

        if (self::supported()) {
            $args['amountCent'] = $amountCent;
            $args['country'] = $country;
        }

        return call_user_func_array([Basket::class, 'make'], $args);
    }

    /**
     * Welches Feld eine Ablehnung des Korbs betrifft, und ihr Satz fuer die
     * Kaeuferin. Null, wenn die Ablehnung keinen Satz mitbringt.
     *
     * `AmountNotAccepted` (offers 1.12) gehoert ans Betragsfeld,
     * `OfferNotAvailable` oben an die Kasse. Nach Namen gefragt, nicht per
     * `instanceof`: gegen offers 1.11 gibt es beide Klassen nicht.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function refusal(Throwable $e, Offer $offer): ?array
    {
        $satz = Sibling::call($e, 'buyerMessage');

        if (! is_string($satz) || $satz === '') {
            return null;
        }

        $feld = match (true) {
            is_a($e, 'Goldnead\StatamicOffers\Support\AmountNotAccepted') => 'amount',
            is_a($e, 'Goldnead\StatamicOffers\Support\OfferNotAvailable') => 'offer',
            default => self::isPayWhatYouWant($offer) ? 'amount' : 'offer',
        };

        return [$feld, $satz];
    }

    /**
     * Der Betrag aus dem Formular, in Cent, oder null, wenn keiner kam.
     *
     * `amount_cent` fuer eine Vorlage, die rechnet; `amount` fuer ein Feld, in
     * das ein Mensch „42,50" oder „42.50" tippt. Was sich nicht lesen laesst,
     * ist -1: ein Betrag, den es nicht geben kann, und den der Korb ablehnt.
     */
    public static function amountFrom(Request $request): ?int
    {
        $cent = $request->input('amount_cent');

        if (is_numeric($cent)) {
            return (int) $cent;
        }

        $raw = trim((string) $request->input('amount', ''));

        if ($raw === '') {
            return null;
        }

        $raw = str_replace([' ', "\u{00A0}"], '', $raw);

        // „1.250,50" und „1,250.50": das letzte Trennzeichen ist das Komma.
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = strrpos($raw, ',') > strrpos($raw, '.')
                ? str_replace(['.', ','], ['', '.'], $raw)
                : str_replace(',', '', $raw);
        } else {
            $raw = str_replace(',', '.', $raw);
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
            return -1;
        }

        return (int) round(((float) $raw) * 100);
    }

    // ------------------------------------------------------------ Laenderregel

    public static function hasCountryRule(Offer $offer): bool
    {
        $modus = Sibling::call($offer, 'countryMode');

        return is_string($modus) && $modus !== 'all';
    }

    /**
     * Die Laenderfrage der Kasse, oder null, wenn das Angebot keine Regel hat
     * oder das Land schon bekannt ist.
     *
     * Bei „nur in" stehen genau die erlaubten Laender zur Wahl, bei „ueberall
     * ausser" alle ausser den ausgeschlossenen. Die Namen kommen aus Statamics
     * Laenderverzeichnis, in der Sprache der Seite.
     *
     * @return array<string, mixed>|null
     */
    public static function countryQuestion(Offer $offer, ?FunnelVisit $visit): ?array
    {
        if (! self::hasCountryRule($offer)) {
            return null;
        }

        $bekannt = (($visit->meta ?? [])['billing'] ?? [])['country'] ?? null;

        if (is_string($bekannt) && $bekannt !== '') {
            return null;
        }

        $liste = (array) Sibling::call($offer, 'countryList');
        $alle = self::countryNames();
        $codes = Sibling::call($offer, 'countryMode') === 'only'
            ? $liste
            : array_values(array_diff(array_keys($alle), $liste));

        // Nach einer Ablehnung steht das zuletzt gewaehlte Land wieder da.
        $alt = is_string(old('country')) ? strtoupper(old('country')) : null;

        $options = array_map(fn (string $code) => [
            'value' => $code,
            'label' => $alle[$code] ?? $code,
            'selected' => $code === $alt,
        ], $codes);

        usort($options, fn (array $a, array $b) => strcoll($a['label'], $b['label']));

        // Ohne Verzeichnis und bei „ueberall ausser" gaebe es keine Auswahl:
        // dann ein Feld fuer den Code, wie in der Anmeldung.
        return ['options' => $options, 'free' => $options === []];
    }

    /**
     * ISO-2 → Name, aus dem Verzeichnis des Kerns.
     *
     * Das Verzeichnis ist nach ISO-3 geschluesselt; die Kasse und
     * `payments.country` sprechen ISO-2. Faellt das Verzeichnis aus, bleiben
     * die Codes stehen: haesslich, aber die Kasse geht.
     *
     * @return array<string, string>
     */
    public static function countryNames(): array
    {
        try {
            $dictionary = Dictionary::find('countries');
            $out = [];

            foreach (array_keys((array) $dictionary->options()) as $iso3) {
                $item = $dictionary->get((string) $iso3);
                $data = $item?->data() ?? [];
                $iso2 = $data['iso2'] ?? null;

                if (is_string($iso2) && $iso2 !== '') {
                    $out[strtoupper($iso2)] = (string) ($data['name'] ?? $iso2);
                }
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** Ein Betrag ohne ueberfluessige Nullen, fuer `min`/`max` eines Zahlenfelds. */
    protected static function plain(int $cent): string
    {
        return rtrim(rtrim(number_format($cent / 100, 2, '.', ''), '0'), '.');
    }
}
