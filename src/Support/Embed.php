<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ein Funnel im Rahmen einer fremden Seite (F4).
 *
 * `embed.js` oeffnet einen Funnel-Schritt als Popup oder bettet ihn ein, als
 * iframe mit `?embed=1`. Drei Probleme hat so ein Rahmen, und diese Klasse
 * loest alle drei an einer Stelle:
 *
 * 1. **Wer darf rahmen.** Jede Funnel-Seite schickt
 *    `Content-Security-Policy: frame-ancestors 'self' <Domains des Funnels>`.
 *    Ohne eingetragene Domain rahmt niemand ausser der Site selbst.
 * 2. **Keine Cookies.** In einem Rahmen auf einer fremden Seite schickt der
 *    Browser die Cookies dieser Site nicht mit (`SameSite=Lax`, Safari
 *    ohnehin nie): kein Besuchs-Cookie, keine Sitzung, kein CSRF-Token. Der
 *    Besuch reist deshalb **signiert** in den Adressen (`w`, `_walk`), mit
 *    Ablaufzeit. Statt des CSRF-Tokens prueft die eingebettete Route, dass
 *    das Formular von dieser Site kommt (`Origin`, sonst `Referer`): eine
 *    fremde Seite kann den Rahmen zeigen, aber nicht in ihm absenden.
 *    Fehlermeldungen, die sonst in der Sitzung liegen, reisen signiert im
 *    Parameter `e`.
 * 3. **Bezahlt wird oben.** Stripe und Mollie verweigern sich jedem Rahmen.
 *    Der Bestellknopf zielt deshalb auf `_top`. Die Rueckkehr vom Anbieter
 *    traegt nicht den Weg, sondern ein Einmal-Token, an die Zahlung gebunden
 *    ({@see returnLink()}); die Seite loest es ein und laedt ohne es neu.
 *
 * **Der signierte Weg gilt nur im Rahmen** ({@see tokenFromRequest()}):
 * ausserhalb wuerde ein Link mit fremdem Weg den Besuch eines anderen
 * uebernehmen (Kritik 23.09.2026). Im Rahmen wird nie ein Cookie geschrieben.
 */
class Embed
{
    public const QUERY = 'embed';

    public const WALK = 'w';

    public const WALK_INPUT = '_walk';

    public const ERRORS = 'e';

    public static function requested(Request $request): bool
    {
        return $request->query(self::QUERY) === '1'
            || ($request->route()?->getName() === 'statamic-funnels.advance-embed');
    }

    /** `'self'` und die Domains des Funnels, als Wert fuer `frame-ancestors`. */
    public static function frameAncestors(?Funnel $funnel): string
    {
        return trim("frame-ancestors 'self' ".implode(' ', FunnelSettings::of($funnel)['embed_domains']));
    }

    /** Der Rahmen-Header und, im Rahmen, eine Referrer-Regel, die den Weg nicht nach draussen traegt. */
    public static function protect(Response $response, ?Funnel $funnel, bool $embedded): Response
    {
        $response->headers->set('Content-Security-Policy', self::frameAncestors($funnel));

        // Ein X-Frame-Options der Site wuerde die erlaubten Domains ueberstimmen.
        // `frame-ancestors` ersetzt ihn in jedem heutigen Browser.
        $response->headers->remove('X-Frame-Options');

        if ($embedded) {
            $response->headers->set('Referrer-Policy', 'same-origin');
        }

        return $response;
    }

    // ------------------------------------------------------------ Der Weg

    /** Der Besuch als signierter Wert fuer Adressen, gueltig bis `$until`. */
    public static function walkLink(string $token, ?int $until = null): string
    {
        $until ??= now()->addMinutes(max(1, (int) config('statamic-funnels.embed.link_minutes', 180)))->getTimestamp();

        return $token.'.'.$until.'.'.self::sign($token.'|'.$until);
    }

    /** Das Besuchs-Token aus einem signierten Wert, oder null. */
    public static function tokenFrom(?string $value): ?string
    {
        if (! is_string($value) || substr_count($value, '.') !== 2) {
            return null;
        }

        [$token, $until, $sig] = explode('.', $value);

        if (preg_match('/^[A-Za-z0-9]{32}$/', $token) !== 1 || ! ctype_digit($until)) {
            return null;
        }

        if (! hash_equals(self::sign($token.'|'.$until), $sig) || (int) $until < now()->getTimestamp()) {
            return null;
        }

        return $token;
    }

    /**
     * Der Weg aus dieser Anfrage: `_walk` im Formular oder in der Adresse, sonst `w`.
     *
     * **Nur im Rahmen.** Ausserhalb eines Rahmens gilt ein signierter Weg nie:
     * wer einem anderen `?w=<eigener Weg>` schickt, uebernimmt sonst dessen
     * Besuch (Adresse, Name, Mandat fuer den Ein-Klick-Upsell). Im Rahmen
     * zaehlt er nur, wenn der Browser die Anfrage als Rahmen meldet
     * (`Sec-Fetch-Dest: iframe`) oder es die eingebettete Route ist, die ihre
     * Herkunft selbst prueft. Ohne den Kopf (alte Browser) wird geglaubt.
     */
    public static function tokenFromRequest(Request $request): ?string
    {
        if (! self::requested($request) || ! self::fetchedAsFrame($request)) {
            return null;
        }

        $wert = $request->query(self::WALK_INPUT) ?? $request->input(self::WALK_INPUT) ?? $request->query(self::WALK);

        return self::tokenFrom(is_string($wert) ? $wert : null);
    }

    /** Kommt die Anfrage aus einem Rahmen, soweit der Browser es sagt? */
    public static function fetchedAsFrame(Request $request): bool
    {
        if ($request->route()?->getName() === 'statamic-funnels.advance-embed') {
            return true;
        }

        // Ohne den Kopf (alte Browser) nein: fail closed. Ein Rahmen, der dann
        // keinen Weg mitfuehren kann, beginnt jede Seite neu; das ist
        // aergerlich, aber keine Uebernahme eines fremden Besuchs.
        return in_array($request->headers->get('Sec-Fetch-Dest'), ['iframe', 'frame', 'embed'], true);
    }

    // --------------------------------------------------- Rueckweg vom Anbieter

    public const RETURN = 'fr';

    /**
     * Das Cookie, das den Rueckweg an den Browser bindet, der bestellt hat.
     *
     * Gesetzt von der Bestellung oben (der Bestellknopf im Rahmen zielt auf
     * `_top`), `Lax`, so lange wie der Rueckweg gilt. Ein Rueckweg ohne dieses
     * Cookie ist ein Link, den jemand anderes geschickt hat.
     */
    public const RETURN_COOKIE = 'statamic_funnel_return';

    /** Das Einmal-Token aus einem Rueckweg, oder null. */
    public static function nonceFrom(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $nonce = explode('.', (string) ($query[self::RETURN] ?? ''), 2)[1] ?? null;

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    /**
     * Der Rueckweg vom Anbieter nach einem Kauf aus dem Rahmen.
     *
     * Kein Weg in der Adresse, sondern ein Einmal-Token am Besuch: gueltig fuer
     * `embed.link_minutes`, gebunden an die Zahlung, die gleich angelegt wird
     * ({@see bindReturn()}), und nach dem ersten Aufruf verbraucht. Die Seite
     * leitet danach auf die Adresse ohne Token um, damit es weder im Verlauf
     * noch in einem Tracking-Aufruf steht.
     */
    public static function returnLink(string $url, FunnelVisit $visit): string
    {
        $nonce = Str::random(40);
        $meta = $visit->meta ?? [];
        $links = array_filter((array) ($meta['return_links'] ?? []), fn ($l) => is_array($l) && ($l['until'] ?? 0) >= now()->getTimestamp());
        $links[$nonce] = [
            'until' => now()->addMinutes(max(1, (int) config('statamic-funnels.embed.link_minutes', 180)))->getTimestamp(),
            'payment_id' => null,
        ];
        $meta['return_links'] = array_slice($links, -5, null, true);
        $visit->forceFill(['meta' => $meta])->save();

        return self::withQuery($url, [self::RETURN => $visit->getKey().'.'.$nonce]);
    }

    /** Das Token an die Zahlung binden, die fuer diesen Rueckweg angelegt wurde. */
    public static function bindReturn(string $url, FunnelVisit $visit, int $paymentId): void
    {
        $nonce = self::nonceFrom($url);
        $meta = $visit->meta ?? [];

        if ($nonce === null || ! isset($meta['return_links'][$nonce])) {
            return;
        }

        $meta['return_links'][$nonce]['payment_id'] = $paymentId;
        $visit->forceFill(['meta' => $meta])->save();
    }

    /**
     * Das Einmal-Token aus der Adresse einloesen: das Token des Besuchs, oder null.
     *
     * Verbraucht wird es in jedem Fall, auch wenn es nicht passt.
     */
    public static function consumeReturn(Funnel $funnel, Request $request): ?string
    {
        $wert = $request->query(self::RETURN);

        if (! is_string($wert) || ! str_contains($wert, '.')) {
            return null;
        }

        [$id, $nonce] = explode('.', $wert, 2);
        $visit = ctype_digit($id) ? FunnelVisit::query()->where('funnel_id', $funnel->id)->find((int) $id) : null;

        if (! $visit) {
            return null;
        }

        $meta = $visit->meta ?? [];
        $link = $meta['return_links'][$nonce] ?? null;

        if (! is_array($link)) {
            return null;
        }

        // Nur der Browser, der bestellt hat. Ein anderer, der den Link
        // bekommen hat, bekommt den Besuch nicht, und das Token bleibt fuer
        // den richtigen stehen.
        $bindung = $request->cookie(self::RETURN_COOKIE);

        if (! is_string($bindung) || ! hash_equals($nonce, $bindung)) {
            return null;
        }

        unset($meta['return_links'][$nonce]);
        $visit->forceFill(['meta' => $meta])->save();

        $gueltig = ($link['until'] ?? 0) >= now()->getTimestamp()
            && is_int($link['payment_id'] ?? null)
            && Payment::query()->whereKey($link['payment_id'])->exists();

        return $gueltig ? (string) $visit->token : null;
    }

    /**
     * Ein Skript ganz vorn im Kopf, das `w` und `e` aus der Adresse nimmt.
     *
     * Vor jedem anderen Skript, damit weder ein Pixel noch ein Analyse-Code
     * den signierten Weg zu sehen bekommt.
     */
    public static function withAddressCleanup(string $html): string
    {
        $skript = "<script>(function(){try{var u=new URL(window.location.href),d=false;['".self::WALK."','".self::ERRORS."'].forEach(function(k){if(u.searchParams.has(k)){u.searchParams['delete'](k);d=true;}});if(d){window.history.replaceState(window.history.state,'',u.toString());}}catch(e){}})();</script>";

        if (preg_match('/<head\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $nach = $m[0][1] + strlen($m[0][0]);

            return substr($html, 0, $nach)."\n".$skript.substr($html, $nach);
        }

        return $skript."\n".$html;
    }

    /** Die Adresse ohne Rueckweg-Token. */
    public static function withoutReturn(string $url): string
    {
        return self::withoutQuery($url, [self::RETURN]);
    }

    /** Eine Adresse mit dem Weg und, fuer Seiten im Rahmen, mit `embed=1`. */
    public static function carry(string $url, string $token, bool $framed = true, array $errors = []): string
    {
        $extra = [self::WALK => self::walkLink($token)];

        if ($framed) {
            $extra = [self::QUERY => '1'] + $extra;
        }

        if ($errors !== []) {
            $extra[self::ERRORS] = self::packErrors($errors);
        }

        return self::withQuery($url, $extra);
    }

    /**
     * Stammt dieses Formular von dieser Site?
     *
     * `Origin` zuerst; ohne ihn (alte Browser) der `Referer`. Ohne beides
     * nicht: eine eingebettete Route ohne CSRF-Token und ohne Herkunft waere
     * ein Formular, das jede Seite absenden kann.
     */
    public static function sameOrigin(Request $request): bool
    {
        $eigene = strtolower($request->getSchemeAndHttpHost());
        $origin = $request->headers->get('Origin');

        if (is_string($origin) && $origin !== '' && $origin !== 'null') {
            return strtolower(rtrim($origin, '/')) === $eigene;
        }

        $referer = $request->headers->get('Referer');

        if (! is_string($referer) || $referer === '') {
            return false;
        }

        $teile = parse_url($referer);

        if (! is_array($teile) || ! isset($teile['scheme'], $teile['host'])) {
            return false;
        }

        $herkunft = strtolower($teile['scheme'].'://'.$teile['host'].(isset($teile['port']) ? ':'.$teile['port'] : ''));

        return $herkunft === $eigene;
    }

    /**
     * Die Antwort der eingebetteten Route fuer den Rahmen passend machen.
     *
     * Eine Weiterleitung auf diese Site bekommt den Weg und `embed=1`, und die
     * Fehler, die gerade in die Sitzung geschrieben wurden, reisen signiert
     * mit — die Sitzung selbst kommt im Rahmen nicht an.
     */
    public static function adapt(Response $response, Request $request, string $token, bool $framed): Response
    {
        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        $ziel = $response->getTargetUrl();

        if (! self::isOwn($ziel, $request)) {
            return $response;
        }

        $errors = [];
        $bag = $request->hasSession() ? $request->session()->get('errors') : null;

        if ($bag instanceof ViewErrorBag) {
            $errors = array_values($bag->getBag('default')->all());
        }

        // Nicht zweimal: eine Adresse, die den Weg schon traegt, bekommt ihn frisch.
        $ziel = self::withoutQuery($ziel, [self::QUERY, self::WALK, self::ERRORS]);

        $response->setTargetUrl(self::carry($ziel, $token, $framed, $errors));

        return $response;
    }

    /**
     * Fehler aus dem Parameter `e`, wenn die Signatur stimmt.
     *
     * @return list<string>
     */
    public static function errorsFrom(Request $request): array
    {
        $wert = $request->query(self::ERRORS);

        if (! is_string($wert) || substr_count($wert, '.') !== 1) {
            return [];
        }

        [$daten, $sig] = explode('.', $wert);

        if (! hash_equals(self::sign('e|'.$daten), $sig)) {
            return [];
        }

        $liste = json_decode((string) base64_decode(strtr($daten, '-_', '+/'), true), true);

        return is_array($liste) ? array_values(array_filter($liste, 'is_string')) : [];
    }

    /** @param  list<string>  $errors */
    protected static function packErrors(array $errors): string
    {
        $daten = rtrim(strtr(base64_encode((string) json_encode(array_values(array_slice($errors, 0, 5)))), '+/', '-_'), '=');

        return $daten.'.'.self::sign('e|'.$daten);
    }

    protected static function isOwn(string $url, Request $request): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        $teile = parse_url($url);

        if (! is_array($teile) || ! isset($teile['host'])) {
            return false;
        }

        return strtolower($teile['host']) === strtolower($request->getHost());
    }

    /** @param  array<string, string>  $extra */
    protected static function withQuery(string $url, array $extra): string
    {
        [$ohne, $fragment] = array_pad(explode('#', $url, 2), 2, null);

        return $ohne.(str_contains($ohne, '?') ? '&' : '?').http_build_query($extra).($fragment !== null ? '#'.$fragment : '');
    }

    /** @param  list<string>  $keys */
    protected static function withoutQuery(string $url, array $keys): string
    {
        $teile = parse_url($url);

        if (! is_array($teile) || ! isset($teile['query'])) {
            return $url;
        }

        parse_str($teile['query'], $query);

        foreach ($keys as $key) {
            unset($query[$key]);
        }

        $basis = strtok($url, '?');

        return $basis.($query === [] ? '' : '?'.http_build_query($query)).(isset($teile['fragment']) ? '#'.$teile['fragment'] : '');
    }

    protected static function sign(string $data): string
    {
        return substr(hash_hmac('sha256', 'statamic-funnels|'.$data, (string) config('app.key')), 0, 32);
    }
}
