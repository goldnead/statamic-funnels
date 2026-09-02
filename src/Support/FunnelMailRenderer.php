<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use RuntimeException;

/**
 * Aus Knoten und Besuch eine fertige Mail machen.
 *
 * Die Vorlage rendert `statamic-email-templates` — Bard zu HTML, Preheader,
 * Marken-Layout, alles wie bei einer Automation oder Kampagne. Dieses Addon
 * fuellt nur die Platzhalter, und zwar mit dem, was ein Funnel weiss:
 *
 * - `visitor.email`, `visitor.name`
 * - `contact.*` — dieselben Namen wie in jeder anderen Vorlage der Familie,
 *   damit eine Willkommensmail nicht zweimal geschrieben werden muss
 * - `funnel.title`, `funnel.handle`, `funnel.url`, `funnel.continue_url`
 * - `step.label`
 * - `order.reference`, `order.total`, `order.lines`, `order.email` — nur nach
 *   einem bezahlten Kauf; vorher bleiben die Platzhalter sichtbar stehen
 */
class FunnelMailRenderer
{
    /**
     * Eigene Schluessel, deren Wert schon Markup ist und deshalb roh eingesetzt
     * wird. Genau einer, und er hat einen Grund, der sich nicht wegkonstruieren
     * laesst: `order.lines` ist eine Liste, `MergeVariables` kennt nur flache
     * Skalare und keine Schleife, und in einer HTML-Mail trennt Zeilen nur
     * Markup — ein `\n` faellt beim Rendern zusammen. Der Trenner muss also ein
     * `<br>` sein, und ein `<br>` ueberlebt kein Escaping.
     *
     * Deshalb werden die Teile in {@see self::variables()} einzeln mit `e()`
     * escaped, dort wo sie zu Markup zusammengesetzt werden, und der Schluessel
     * wird pro Aufruf als roh angemeldet. Er gehoert NICHT in
     * `MergeVariables::RAW_VARIABLES` — dort waere er fuer jeden Konsumenten des
     * Schwester-Addons roh, auch fuer die, die ihn nie escapt haben.
     *
     * @var list<string>
     */
    public const RAW_VARIABLES = ['order.lines'];

    /**
     * @return array{subject: string, html: string}
     *
     * @throws RuntimeException wenn keine Vorlage gewaehlt, das Addon nicht da
     *                          oder die Vorlage nicht zu finden ist
     */
    public function render(FunnelStep $step, FunnelVisit $visit, ?array $sampleOrder = null): array
    {
        $slug = trim((string) $step->config('template'));

        if ($slug === '') {
            throw new RuntimeException(__('statamic-funnels::messages.mail_error_no_template'));
        }

        if (! class_exists(MailTemplates::FACADE)) {
            throw new RuntimeException(__('statamic-funnels::messages.mail_error_addon_missing'));
        }

        $resolved = MailTemplates::resolve($slug);

        if ($resolved === null) {
            throw new RuntimeException(__('statamic-funnels::messages.mail_error_template_missing', ['slug' => $slug]));
        }

        $data = $this->variables($step, $visit, $sampleOrder);

        $subject = trim((string) $step->config('subject_override')) ?: $resolved['subject'];

        return [
            // Der Betreff ist Text, kein HTML: dort bleibt der Wert roh, sonst
            // stuende `Mueller &amp; Soehne` in der Betreffzeile.
            'subject' => MailTemplates::merge($subject, $data, false),
            // Der Koerper ist HTML: `visitor.name` kommt aus dem Formular und
            // wird escaped. Einzige Ausnahme ist `order.lines`, das hier schon
            // als Markup gebaut wird — siehe variables().
            'html' => MailTemplates::merge($resolved['body'], $data, true, self::RAW_VARIABLES),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $sampleOrder
     * @return array<string, mixed>
     */
    public function variables(FunnelStep $step, FunnelVisit $visit, ?array $sampleOrder = null): array
    {
        /** @var Funnel $funnel */
        $funnel = $step->relationLoaded('funnel') ? $step->funnel : $visit->funnel;

        $name = trim((string) $visit->name);
        $first = $name === '' ? '' : explode(' ', $name)[0];

        $current = $visit->current_node_key ? $funnel->stepByKey($visit->current_node_key) : null;

        $order = $sampleOrder ?? OrderSummary::forVisit($visit->exists ? $visit : null);

        return self::siteVariables() + [
            'visitor' => [
                'email' => (string) $visit->email,
                'name' => $name,
            ],
            'contact' => [
                'email' => (string) $visit->email,
                'full_name' => $name,
                'first_name' => $first,
                'salutation' => $first === '' ? __('statamic-funnels::messages.mail_salutation_anonymous') : __('statamic-funnels::messages.mail_salutation', ['name' => $first]),
            ],
            'funnel' => [
                'title' => (string) $funnel->title,
                'handle' => (string) $funnel->handle,
                'url' => route('statamic-funnels.entry', $funnel->handle),
                'continue_url' => $current && $current->slug
                    ? route('statamic-funnels.step', [$funnel->handle, $current->slug])
                    : route('statamic-funnels.entry', $funnel->handle),
            ],
            'step' => [
                'label' => (string) ($step->label ?: $step->node_key),
            ],
            'order' => $order === null ? [] : [
                'reference' => (string) ($order['reference'] ?? ''),
                'total' => (string) ($order['total'] ?? ''),
                'currency' => (string) ($order['currency'] ?? ''),
                'email' => (string) ($order['email'] ?? ''),
                // Die eine Stelle, an der dieses Addon selbst Markup baut, und
                // damit die eine Stelle, an der es selbst escapen muss: der
                // Wert wird als `order.lines` roh eingesetzt
                // ({@see self::RAW_VARIABLES}), also kommt jeder Teil hier
                // genau einmal durch `e()`.
                'lines' => implode('<br>', array_map(
                    fn (array $line) => e((string) $line['name']).' – '.e((string) $line['amount']),
                    (array) ($order['lines'] ?? []),
                )),
            ],
        ];
    }

    /**
     * Was jede Vorlage der Familie ausserdem kennt: `sender.*`, `date`,
     * `unsubscribe_url`.
     *
     * Aus den Vorgaben von `MergeVariables`, aber **nur diese Schluessel**:
     * die Vorgaben tragen daneben eine Beispiel-Empfaengerin, und die darf in
     * einer echten Mail nirgends auftauchen. Der Absender dort ist der der
     * Marke bzw. aus `mail.from`, also der richtige auch fuer den Versand.
     *
     * @return array<string, mixed>
     */
    protected static function siteVariables(): array
    {
        $defaults = Sibling::call(MailTemplates::MERGE, 'defaults');

        if (! is_array($defaults)) {
            return [];
        }

        return array_intersect_key($defaults, array_flip(['sender', 'date', 'unsubscribe_url']));
    }
}
