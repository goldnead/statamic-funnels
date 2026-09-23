<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\Funnel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 *    Der Bestellknopf zielt deshalb auf `_top`, und die Rueckkehr vom Anbieter
 *    traegt den signierten Weg, damit die Danke-Seite den Kauf findet.
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

    /** Der Weg aus dieser Anfrage: `_walk` im Formular oder in der Adresse, sonst `w`. */
    public static function tokenFromRequest(Request $request): ?string
    {
        $wert = $request->query(self::WALK_INPUT) ?? $request->input(self::WALK_INPUT) ?? $request->query(self::WALK);

        return self::tokenFrom(is_string($wert) ? $wert : null);
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
