<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\Funnel;

/**
 * Was ein Funnel ausser seinem Graphen einstellt.
 *
 * In `funnels.meta.settings`, gesaeubert gelesen: der In-App-Hinweis (F3), die
 * Domains, auf denen er eingebettet werden darf (F4), die Tracking-Codes (F6)
 * und die Meta-Pixel-ID (F7). Eine eigene Stelle, weil vier Features dieselbe
 * Zeile lesen und jedes einen anderen Rueckfall braucht.
 */
class FunnelSettings
{
    /**
     * @return array{in_app_enabled: bool, in_app_text: string|null, embed_domains: list<string>, tracking_head: string|null, tracking_thanks: string|null, meta_pixel_id: string|null, tracking_head_service: string|null, tracking_thanks_service: string|null, meta_pixel_service: string|null}
     */
    public static function of(?Funnel $funnel): array
    {
        $raw = (array) (($funnel->meta ?? [])['settings'] ?? []);

        return [
            'in_app_enabled' => array_key_exists('in_app_enabled', $raw)
                ? filter_var($raw['in_app_enabled'], FILTER_VALIDATE_BOOLEAN)
                : true,
            'in_app_text' => self::text($raw['in_app_text'] ?? null),
            'embed_domains' => self::origins($raw['embed_domains'] ?? []),
            'tracking_head' => self::text($raw['tracking_head'] ?? null),
            'tracking_thanks' => self::text($raw['tracking_thanks'] ?? null),
            'meta_pixel_id' => is_scalar($raw['meta_pixel_id'] ?? null) && preg_match('/^\d{5,32}$/', trim((string) $raw['meta_pixel_id'])) === 1
                ? trim((string) $raw['meta_pixel_id'])
                : null,
            // Der Dienst in statamic-consent je Code. Null heisst: der aus der
            // Config (`tracking.consent_service`).
            'tracking_head_service' => self::service($raw['tracking_head_service'] ?? null),
            'tracking_thanks_service' => self::service($raw['tracking_thanks_service'] ?? null),
            'meta_pixel_service' => self::service($raw['meta_pixel_service'] ?? null),
        ];
    }

    /** Die Schluessel, die nur mit dem Recht „Tracking-Code bearbeiten" geaendert werden duerfen. */
    public const TRACKING_KEYS = [
        'tracking_head', 'tracking_thanks', 'meta_pixel_id',
        'tracking_head_service', 'tracking_thanks_service', 'meta_pixel_service',
    ];

    /** Ein Dienst-Handle, wie statamic-consent sie schreibt, oder null. */
    public static function service(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^[a-z0-9_-]{1,64}$/i', $value) === 1 ? $value : null;
    }

    /**
     * Die Domains, auf denen der Funnel eingebettet werden darf, als Origins.
     *
     * Angenommen wird, was ein Mensch tippt: `beispiel.de`,
     * `https://www.beispiel.de/`, eine Liste mit Kommas oder Zeilen.
     * Herauskommt `https://beispiel.de`, ohne Pfad. Ohne Schema gilt https:
     * eine Seite, die ueber http einbettet, bekommt ohnehin keine Zahlung
     * durch. Was sich nicht als Host lesen laesst, faellt heraus; der Editor
     * prueft es vor dem Speichern mit {@see invalidOrigins()}.
     *
     * @return list<string>
     */
    public static function origins(mixed $value): array
    {
        $out = [];

        foreach (self::entries($value) as $entry) {
            $origin = self::origin($entry);

            if ($origin !== null) {
                $out[] = $origin;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Was sich nicht als Domain lesen laesst, fuer die Fehlermeldung.
     *
     * @return list<string>
     */
    public static function invalidOrigins(mixed $value): array
    {
        return array_values(array_filter(self::entries($value), fn (string $e) => self::origin($e) === null));
    }

    /** @return list<string> */
    protected static function entries(mixed $value): array
    {
        $parts = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);

        return array_values(array_filter(
            array_map(fn ($p) => is_scalar($p) ? trim((string) $p) : '', (array) $parts),
            fn (string $p) => $p !== '',
        ));
    }

    protected static function origin(string $entry): ?string
    {
        $url = str_contains($entry, '://') ? $entry : 'https://'.$entry;
        $teile = parse_url($url);

        if (! is_array($teile) || ! isset($teile['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($teile['scheme'] ?? 'https'));
        $host = strtolower($teile['host']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        // Ein Stern ist fuer Subdomains erlaubt (`*.beispiel.de`), sonst nur
        // das, was in einem Hostnamen stehen kann.
        if (preg_match('/^(\*\.)?([a-z0-9-]+\.)*[a-z0-9-]+$/', $host) !== 1) {
            return null;
        }

        return $scheme.'://'.$host.(isset($teile['port']) ? ':'.$teile['port'] : '');
    }

    protected static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
