<?php

namespace Goldnead\StatamicFunnels\Support;

use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Throwable;

/**
 * Die Bruecke zu `goldnead/statamic-email-templates`.
 *
 * Optional, wie jede Schwester in dieser Familie: das Addon hier verlangt sie
 * nicht in der `composer.json`, und ein Funnel ohne sie ist ein Funnel ohne
 * Mail-Knoten, kein kaputter Funnel.
 *
 * **Zwei Fragen, weil eine nicht reicht.** Die Collection `et_templates` ist
 * eine Datei unter `content/collections/` und bleibt liegen, wenn jemand das
 * Addon aus der `composer.json` nimmt. Nur `handleExists()` zu fragen hiesse:
 * das Auswahlfeld bietet weiter Vorlagen an, die niemand mehr rendern kann.
 */
class MailTemplates
{
    public const FACADE = '\Goldnead\EmailTemplates\Facades\EmailTemplates';

    public const MERGE = '\Goldnead\EmailTemplates\Support\MergeVariables';

    public const COLLECTION = 'et_templates';

    public static function installed(): bool
    {
        if (! class_exists(self::FACADE)) {
            return false;
        }

        try {
            return Collection::handleExists(self::COLLECTION);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Die waehlbaren Vorlagen, Slug als Wert.
     *
     * **Nur veroeffentlichte.** Ein Entwurf im Auswahlfeld ist kein Entwurf
     * mehr: wer ihn waehlt, schickt ihn an Menschen.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        if (! self::installed()) {
            return [];
        }

        try {
            return Entry::whereCollection(self::COLLECTION)
                ->filter(fn ($entry) => $entry->published())
                ->map(fn ($entry) => [
                    'value' => (string) $entry->slug(),
                    'label' => trim((string) ($entry->get('title') ?: $entry->slug())).' ('.$entry->slug().')',
                ])
                ->filter(fn (array $option) => $option['value'] !== '')
                ->sortBy('label')
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Eine Vorlage, aufgeloest zu Betreff und fertigem HTML — oder null.
     *
     * Der Facade-Aufruf steht hinter `class_exists`, nie hinter
     * `method_exists`: eine Facade leitet ueber `__callStatic` weiter und
     * deklariert nichts von dem, was sie weiterleitet.
     *
     * @return array{subject: string, body: string}|null
     */
    public static function resolve(string $slug): ?array
    {
        if (! class_exists(self::FACADE) || $slug === '') {
            return null;
        }

        $facade = self::FACADE;
        $resolved = $facade::resolve($slug);

        if ($resolved === null) {
            return null;
        }

        return [
            'subject' => (string) $resolved->subject,
            'body' => (string) $resolved->body,
        ];
    }

    /**
     * `{{ dotted.key }}` gegen die Daten des Besuchs aufloesen.
     *
     * Ueber die `MergeVariables` des Schwester-Addons, damit eine Vorlage im
     * Funnel dieselben Platzhalter versteht wie in einer Automation oder einer
     * Kampagne. Fehlt die Klasse — aeltere Fassung des Addons — bleibt der
     * Text, wie er ist; ein sichtbarer Platzhalter ist besser als ein leeres
     * Feld, das niemand bemerkt.
     *
     * `$escape` und `$raw` reicht die Schwester seit 2.3.0 durch: Werte werden
     * dort beim Einsetzen escaped, ausser der Aufrufer sagt fuer eine
     * Nicht-HTML-Ausgabe (Betreff) `false` oder nennt einen eigenen Schluessel,
     * der schon Markup traegt (`order.lines`).
     *
     * **Positional, nicht benannt.** Gegen 2.2.x ist `apply()` zweistellig;
     * zusaetzliche positionale Argumente ignoriert PHP dort stillschweigend
     * (das Verhalten ist dann das alte, ungeescapte), ein unbekanntes benanntes
     * Argument waere ein Fatal mitten im Versand.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $raw  Eigene Schluessel, deren Wert schon Markup ist.
     */
    public static function merge(string $text, array $data, bool $escape = true, array $raw = []): string
    {
        if ($text === '' || ! class_exists(self::MERGE)) {
            return $text;
        }

        $merge = self::MERGE;

        return (string) $merge::apply($text, $data, $escape, $raw);
    }
}
