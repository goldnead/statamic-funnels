<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * Die Betriebswerte, die ein Betreiber im Control Panel ändern darf.
 *
 * Nur die Feldliste. Seite, Formular, Validierung, Speicher und Rechteprüfung
 * kommen aus `goldnead/statamic-brand-context` — siehe {@see ProvidesSettings}.
 *
 * **Was nicht hier steht, und warum.**
 *
 * - `route_prefix`. Wird beim Registrieren der Routen gelesen
 *   (`routes/web.php:14`), also bevor `SettingsManager::apply()` aus
 *   `app->booted()` läuft. Ein Schalter, der erst beim nächsten Deploy wirkt,
 *   ist eine Lüge in der Oberfläche. Außerdem stehen diese URLs auf Flyern.
 * - `thumbnails.disk` und `thumbnails.chrome_path`. Beide beschreiben die
 *   Maschine, nicht die Seite: ein Dateisystem, das öffentlich sein muss, und
 *   ein Pfad zu einem Chromium. Ein falscher Wert fällt erst auf, wenn der
 *   nächste Schritt fotografiert wird.
 * - `thumbnails.cookies` und `thumbnails.hide_selectors`. Eine Abbildung
 *   (Name → Wert) und eine Liste von CSS-Selektoren. Für die Abbildung kennt
 *   der Vertrag dieser Schicht keinen Typ.
 * - `integrations.entitlements`. Steht in der Config, wird aber an keiner
 *   Stelle dieses Addons gelesen (Stand 07.09.2026, geprüft über
 *   `grep -rn entitlements src/ routes/`). Ein Schalter auf dem Bildschirm,
 *   der nichts schaltet, ist schlimmer als kein Schalter.
 */
class Settings implements ProvidesSettings
{
    /**
     * Bleibt für immer stehen: der Wert steht in `brand_settings.namespace` in
     * jeder Zeile, ein neuer Name verwaist jede gespeicherte Änderung.
     */
    public static function settingsNamespace(): string
    {
        return 'funnels';
    }

    /**
     * Nicht identisch mit dem Namensraum. Die Config-Datei heißt
     * `statamic-funnels.php`, jedes `config('statamic-funnels.…')` im Code
     * liest von dort.
     */
    public static function settingsConfigPath(): string
    {
        return 'statamic-funnels';
    }

    public static function settingsPermission(): string
    {
        return 'manage funnels settings';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('statamic-funnels::settings.groups.pages.title'),
                'description' => __('statamic-funnels::settings.groups.pages.description'),
                'fields' => [
                    static::field('styles', 'boolean'),
                    static::field('coupons', 'boolean'),
                    static::field('template_prefix', 'string'),
                    // Leer heißt: das Reset-Formular des Control Panels. Das
                    // ist ein anderer Zustand als eine leere URL.
                    static::field('password_reset_url', 'string', ['nullable' => true]),
                ],
            ],
            [
                'title' => __('statamic-funnels::settings.groups.thumbnails.title'),
                'description' => __('statamic-funnels::settings.groups.thumbnails.description'),
                'fields' => [
                    static::field('thumbnails.enabled', 'boolean'),
                    static::field('thumbnails.width', 'integer', ['min' => 1]),
                    static::field('thumbnails.height', 'integer', ['min' => 1]),
                ],
            ],
            [
                'title' => __('statamic-funnels::settings.groups.integrations.title'),
                'description' => __('statamic-funnels::settings.groups.integrations.description'),
                'fields' => [
                    static::field('integrations.leadhub', 'boolean'),
                ],
            ],
        ];
    }

    /**
     * Ein Feld, mit Beschriftung und Beschreibung aus den Sprachdateien.
     *
     * Der Übersetzungsschlüssel ist der Config-Pfad mit flachgelegten Punkten:
     * ein Punkt im Sprachschlüssel ist für den Übersetzer ein Pfadtrenner.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("statamic-funnels::settings.fields.{$handle}.label"),
            'description' => __("statamic-funnels::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }
}
