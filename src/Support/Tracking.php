<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Contracts\ConversionSender;
use Goldnead\StatamicFunnels\Jobs\SendConversionEvent;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tracking-Code und Meta-Pixel auf den Funnel-Seiten (F6, F7).
 *
 * Drei Arten Code, alle aus dem Funnel oder dem Kassenschritt:
 *
 * - **Kopf-Code** des Funnels, auf jeder Seite.
 * - **Danke-Code** des Funnels und **Kauf-Code** des Kassenschritts, einmal je
 *   bezahltem Kauf, auf der ersten Seite danach. Platzhalter `{amount}`
 *   (`99.00`), `{currency}` (`EUR`), `{order_id}` (die Zahlungsnummer).
 *   „Einmal" steht am Besuch (`meta.tracked_payments`): ein Neuladen der
 *   Danke-Seite ist kein zweiter Kauf.
 * - **Meta-Pixel** mit PageView, InitiateCheckout (beim Bestellen) und
 *   Purchase, jedes mit einer Ereignis-ID, die der Server fuer dieselbe
 *   Sache an die Conversions API schickt. Meta legt beide zu einem zusammen.
 *
 * Ob etwas hinaus darf, sagt {@see TrackingConsent}. Geparkt heisst: nur die
 * `<script>`-Elemente bleiben, als `type="text/plain"` unter dem Dienst der
 * Einwilligung. Ein `<noscript>`-Bild und jedes andere Element fallen heraus,
 * weil sie ohne Skript und damit ohne Einwilligung laden wuerden.
 *
 * Ausgegeben wird in die fertige Antwort, vor `</head>` und `</body>` (oder
 * vorn und hinten, wo es die nicht gibt). So gilt es auch fuer einen Schritt,
 * der eine Statamic-Seite zeigt, ohne dass deren Vorlage etwas wissen muss.
 */
class Tracking
{
    /**
     * Was eine Seite an Code bekommt.
     *
     * @param  array<string, mixed>|null  $offer  Das Angebot, wie die Kasse es zeichnet
     * @return array{head: string, body: string}
     */
    public static function forPage(Funnel $funnel, FunnelStep $step, FunnelVisit $visit, Request $request, ?array $offer): array
    {
        $settings = FunnelSettings::of($funnel);
        $state = TrackingConsent::state($request);
        $pixel = $settings['meta_pixel_id'];

        if ($pixel !== null || $settings['tracking_head'] !== null || $settings['tracking_thanks'] !== null) {
            self::rememberConsent($visit, $request, $state['granted']);
        }

        if ($state['mode'] === TrackingConsent::BLOCK) {
            return ['head' => '', 'body' => ''];
        }

        $head = [];
        $body = [];

        if ($settings['tracking_head'] !== null) {
            $head[] = $settings['tracking_head'];
        }

        if ($pixel !== null) {
            $pageView = 'pv-'.$visit->getKey().'-'.$step->node_key.'-'.Str::lower(Str::random(8));
            $head[] = self::pixelBase($pixel, $pageView);

            if ($state['granted']) {
                self::server($pixel, 'PageView', $pageView, $visit, [], self::pageUrl($request));
            }

            if ($step->type === 'offer' && $offer !== null) {
                $body[] = self::initiateCheckoutScript(
                    self::checkoutEventId($visit, $step),
                    is_string($offer['amount'] ?? null) ? $offer['amount'] : null,
                    (string) ($offer['currency'] ?? 'EUR'),
                );
            }
        }

        foreach (self::untrackedPurchases($visit) as [$payment, $nodeKey]) {
            $vars = [
                '{amount}' => number_format(((int) $payment->amount_cent) / 100, 2, '.', ''),
                '{currency}' => strtoupper((string) ($payment->currency ?: 'EUR')),
                '{order_id}' => (string) $payment->getKey(),
            ];

            if ($settings['tracking_thanks'] !== null) {
                $body[] = strtr($settings['tracking_thanks'], $vars);
            }

            $eigener = $nodeKey !== null ? $funnel->stepByKey($nodeKey)?->config('tracking_purchase') : null;

            if (is_string($eigener) && trim($eigener) !== '') {
                $body[] = strtr($eigener, $vars);
            }

            if ($pixel !== null) {
                $body[] = "<script>if (window.fbq) { fbq('track', 'Purchase', {value: ".$vars['{amount}'].", currency: '".$vars['{currency}']."'}, {eventID: 'purchase-".$payment->getKey()."'}); }</script>";
            }

            self::markTracked($visit, $payment);
        }

        $head = implode("\n", $head);
        $body = implode("\n", $body);

        if ($state['mode'] === TrackingConsent::PARK) {
            $head = self::park($head);
            $body = self::park($body);
        }

        return ['head' => $head, 'body' => $body];
    }

    /** Die ID fuer InitiateCheckout: je Besuch und Kasse, damit Seite und Server dieselbe kennen. */
    public static function checkoutEventId(FunnelVisit $visit, FunnelStep $step): string
    {
        return 'ic-'.$visit->getKey().'-'.$step->node_key;
    }

    /** InitiateCheckout vom Server, wenn jemand auf „Bestellen" drueckt. */
    public static function initiateCheckout(Funnel $funnel, FunnelStep $step, FunnelVisit $visit, Request $request, ?int $valueCent, string $currency): void
    {
        $pixel = FunnelSettings::of($funnel)['meta_pixel_id'];

        if ($pixel === null || ! TrackingConsent::state($request)['granted']) {
            return;
        }

        $custom = ['currency' => strtoupper($currency)];

        if ($valueCent !== null) {
            $custom['value'] = round($valueCent / 100, 2);
        }

        self::server($pixel, 'InitiateCheckout', self::checkoutEventId($visit, $step), $visit, $custom, self::pageUrl($request));
    }

    /**
     * Purchase vom Server, aus dem Webhook.
     *
     * Dort gibt es keinen Browser und kein Einwilligungs-Cookie: es gilt, was
     * beim Besuch galt (`meta.tracking.consent`).
     */
    public static function purchase(FunnelVisit $visit, Payment $payment): void
    {
        $funnel = $visit->funnel;
        $pixel = FunnelSettings::of($funnel)['meta_pixel_id'];
        $tracking = (array) (($visit->meta ?? [])['tracking'] ?? []);

        if ($pixel === null || ($tracking['consent'] ?? false) !== true) {
            return;
        }

        $email = $payment->email ?: $visit->email;

        self::server($pixel, 'Purchase', 'purchase-'.$payment->getKey(), $visit, [
            'value' => round(((int) $payment->amount_cent) / 100, 2),
            'currency' => strtoupper((string) ($payment->currency ?: 'EUR')),
            'order_id' => (string) $payment->getKey(),
        ], $tracking['url'] ?? null, is_string($email) ? $email : null);
    }

    /**
     * Ein Ereignis an die Conversions API, ausserhalb der Anfrage.
     *
     * `user_data` ist eine Liste, keine Durchreiche: gehashte Adresse,
     * Browser-Kennung und IP nur, wenn beim Besuch eingewilligt wurde, und die
     * beiden Meta-Cookies. Sonst nichts vom Kontakt.
     *
     * @param  array<string, mixed>  $custom
     */
    protected static function server(string $pixel, string $name, string $eventId, FunnelVisit $visit, array $custom, ?string $url, ?string $email = null): void
    {
        $sender = app(ConversionSender::class);

        if (! $sender->enabled()) {
            return;
        }

        $tracking = (array) (($visit->meta ?? [])['tracking'] ?? []);
        $email = mb_strtolower(trim((string) ($email ?? $visit->email ?? '')));

        $user = array_filter([
            'em' => $email !== '' ? [hash('sha256', $email)] : null,
            'client_ip_address' => $tracking['ip'] ?? null,
            'client_user_agent' => $tracking['ua'] ?? null,
            'fbp' => $tracking['fbp'] ?? null,
            'fbc' => $tracking['fbc'] ?? null,
        ]);

        $event = array_filter([
            'event_name' => $name,
            'event_time' => now()->getTimestamp(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'event_source_url' => $url,
            'user_data' => $user === [] ? null : $user,
            'custom_data' => $custom === [] ? null : $custom,
        ], fn ($v) => $v !== null);

        try {
            SendConversionEvent::dispatch($pixel, $event);
        } catch (Throwable) {
            // Ein Werbeereignis wirft keine Seite und keinen Webhook um.
        }
    }

    /**
     * Einwilligung und Kennungen am Besuch festhalten, fuer den Webhook.
     *
     * Ohne Einwilligung steht nur `consent: false` da: keine IP, keine
     * Browser-Kennung, keine Meta-Cookies.
     */
    protected static function rememberConsent(FunnelVisit $visit, Request $request, bool $granted): void
    {
        $meta = $visit->meta ?? [];

        if ($granted) {
            $fbc = $request->cookie('_fbc');
            $fbclid = $request->query('fbclid');

            if ((! is_string($fbc) || $fbc === '') && is_string($fbclid) && $fbclid !== '') {
                $fbc = 'fb.1.'.((int) (microtime(true) * 1000)).'.'.$fbclid;
            }

            $tracking = array_filter([
                'consent' => true,
                'ip' => $request->ip(),
                'ua' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
                'fbp' => is_string($request->cookie('_fbp')) ? $request->cookie('_fbp') : null,
                'fbc' => is_string($fbc) && $fbc !== '' ? mb_substr($fbc, 0, 500) : (($meta['tracking'] ?? [])['fbc'] ?? null),
                'url' => self::pageUrl($request),
            ], fn ($v) => $v !== null);
        } else {
            $tracking = ['consent' => false];
        }

        if (($meta['tracking'] ?? null) === $tracking) {
            return;
        }

        $meta['tracking'] = $tracking;
        $visit->forceFill(['meta' => $meta])->save();
    }

    /**
     * Bezahlte Kaeufe dieses Laufs, die noch keinen Danke-Code bekommen haben.
     *
     * @return list<array{0: Payment, 1: string|null}>
     */
    protected static function untrackedPurchases(FunnelVisit $visit): array
    {
        $meta = $visit->meta ?? [];
        $byStep = array_filter((array) ($meta['payments'] ?? []));
        $ids = array_values(array_unique(array_filter(array_merge(array_values($byStep), [$visit->payment_id]))));
        $done = array_map('intval', (array) ($meta['tracked_payments'] ?? []));
        $offen = array_values(array_diff(array_map('intval', $ids), $done));

        if ($offen === []) {
            return [];
        }

        $knoten = array_flip(array_map('intval', $byStep));

        return Payment::query()
            ->whereIn('id', $offen)
            ->orderBy('id')
            ->get()
            ->filter(fn (Payment $p) => $p->isPaid())
            ->map(fn (Payment $p) => [$p, $knoten[(int) $p->getKey()] ?? null])
            ->values()
            ->all();
    }

    protected static function markTracked(FunnelVisit $visit, Payment $payment): void
    {
        $meta = $visit->meta ?? [];
        $meta['tracked_payments'] = array_values(array_unique(array_merge(
            array_map('intval', (array) ($meta['tracked_payments'] ?? [])),
            [(int) $payment->getKey()],
        )));

        $visit->forceFill(['meta' => $meta])->save();
    }

    /** Der Grundcode des Pixels, mit PageView und seiner ID. */
    protected static function pixelBase(string $pixel, string $pageViewId): string
    {
        return "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');\n"
            ."fbq('init', '".$pixel."');\n"
            ."fbq('track', 'PageView', {}, {eventID: '".$pageViewId."'});</script>";
    }

    /** InitiateCheckout im Browser, wenn das Bestellformular abgeschickt wird. */
    protected static function initiateCheckoutScript(string $eventId, ?string $amount, string $currency): string
    {
        $werte = $amount !== null && is_numeric($amount)
            ? '{value: '.number_format((float) $amount, 2, '.', '').", currency: '".strtoupper(preg_replace('/[^A-Za-z]/', '', $currency) ?: 'EUR')."'}"
            : "{currency: '".strtoupper(preg_replace('/[^A-Za-z]/', '', $currency) ?: 'EUR')."'}";

        return "<script>document.addEventListener('submit', function (event) { var form = event.target; if (form && form.classList && form.classList.contains('funnel-offer__accept') && window.fbq) { fbq('track', 'InitiateCheckout', ".$werte.", {eventID: '".$eventId."'}); } });</script>";
    }

    /**
     * Nur die Skripte, geparkt unter dem Dienst der Einwilligung.
     */
    public static function park(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $service = e(TrackingConsent::service());
        $html = (string) preg_replace('#<noscript\b[^>]*>.*?</noscript>#is', '', $html);

        preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $treffer, PREG_SET_ORDER);

        $out = [];

        foreach ($treffer as $skript) {
            $attribute = (string) preg_replace('#\s+(type|data-consent-service)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $skript[1]);
            $out[] = '<script type="text/plain" data-consent-service="'.$service.'"'.$attribute.'>'.$skript[2].'</script>';
        }

        return implode("\n", $out);
    }

    /** Code in eine fertige HTML-Antwort setzen. */
    public static function inject(string $content, string $head, string $body): string
    {
        if ($head !== '') {
            $pos = stripos($content, '</head>');
            $content = $pos === false ? $head."\n".$content : substr_replace($content, $head."\n", $pos, 0);
        }

        if ($body !== '') {
            $pos = strripos($content, '</body>');
            $content = $pos === false ? $content."\n".$body : substr_replace($content, $body."\n", $pos, 0);
        }

        return $content;
    }

    protected static function pageUrl(Request $request): string
    {
        return $request->fullUrlWithoutQuery([Embed::WALK, Embed::ERRORS, Embed::WALK_INPUT, 'fbclid']);
    }
}
