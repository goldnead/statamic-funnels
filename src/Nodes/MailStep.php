<?php

namespace Goldnead\StatamicFunnels\Nodes;

use Goldnead\StatamicFunnels\Support\MailTemplates;

/**
 * Eine Mail, die an einem Schritt haengt.
 *
 * **Kein Schritt, den jemand betritt.** Der Knoten hat keine Seite, keinen
 * Slug und keinen Ausgang; der Besuch laeuft nie hindurch. Was ihn ausloest,
 * ist **dass der Besuch den Ausgang nimmt**, an dem seine Kante haengt:
 *
 * - `default`  — `weiter` auf Einstieg und Seite, `abgeschickt` am Formular.
 * - `accepted` — das Angebot wurde bezahlt (erst wenn der Webhook es sagt).
 * - `declined` — das Angebot wurde abgelehnt.
 *
 * Eine Regel, nicht drei, und dieselbe, die die Leinwand zeichnet: die Kante
 * haengt am Griff mit dem Wort „abgeschickt", also feuert sie beim Abschicken.
 * Der Abschluss hat keinen Ausgang; „der Weg ist zu Ende" ist der Ausgang, der
 * zum Abschluss fuehrt — die Mail haengt an dem.
 *
 * Auf der Leinwand sieht das aus wie bei HighLevel oder Kajabi: die Mail als
 * Abzweig neben dem Weg. Im Verhalten bleibt der Funnel ein Weg, auf dem
 * Menschen stehen — die URL flackert nicht, der Zurueck-Knopf bricht nicht,
 * und die Abbruchstatistik zaehlt keinen Schritt, an dem nie jemand stand.
 *
 * Die Vorlage kommt aus `goldnead/statamic-email-templates`. Ohne das Addon ist
 * der Knoten speicherbar, aber jeder Versand schlaegt sichtbar fehl.
 */
class MailStep extends StepType
{
    public const RECIPIENT_VISITOR = 'visitor';

    public const RECIPIENT_FIXED = 'fixed';

    public const UNIT_MINUTES = 'minutes';

    public const UNIT_HOURS = 'hours';

    public const UNIT_DAYS = 'days';

    public static function handle(): string
    {
        return 'mail';
    }

    public static function kind(): string
    {
        return 'mail';
    }

    public static function label(): string
    {
        return __('statamic-funnels::nodes.mail_label');
    }

    public static function description(): string
    {
        return __('statamic-funnels::nodes.mail_description');
    }

    public static function icon(): string
    {
        return 'mail';
    }

    /** @return list<string> */
    public static function units(): array
    {
        return [self::UNIT_MINUTES, self::UNIT_HOURS, self::UNIT_DAYS];
    }

    /** @return list<string> */
    public static function recipients(): array
    {
        return [self::RECIPIENT_VISITOR, self::RECIPIENT_FIXED];
    }

    public static function outputs(): array
    {
        // Nichts geht von einer Mail aus weiter.
        return [];
    }

    public static function schema(): array
    {
        $templates = MailTemplates::options();

        return [
            [
                'handle' => 'template',
                'type' => 'select',
                'label' => __('statamic-funnels::nodes.field_mail_template'),
                'instructions' => $templates === []
                    ? __('statamic-funnels::nodes.field_mail_template_missing')
                    : __('statamic-funnels::nodes.field_mail_template_help'),
                'options' => $templates,
            ],
            [
                'handle' => 'delay_amount',
                'type' => 'integer',
                'label' => __('statamic-funnels::nodes.field_delay_amount'),
                'instructions' => __('statamic-funnels::nodes.field_delay_amount_help'),
                'default' => 0,
            ],
            [
                'handle' => 'delay_unit',
                'type' => 'select',
                'label' => __('statamic-funnels::nodes.field_delay_unit'),
                'default' => self::UNIT_MINUTES,
                'options' => array_map(
                    fn (string $unit) => ['value' => $unit, 'label' => __('statamic-funnels::nodes.unit_'.$unit)],
                    self::units(),
                ),
            ],
            [
                'handle' => 'recipient',
                'type' => 'select',
                'label' => __('statamic-funnels::nodes.field_recipient'),
                'instructions' => __('statamic-funnels::nodes.field_recipient_help'),
                'default' => self::RECIPIENT_VISITOR,
                'options' => array_map(
                    fn (string $who) => ['value' => $who, 'label' => __('statamic-funnels::nodes.recipient_'.$who)],
                    self::recipients(),
                ),
            ],
            [
                'handle' => 'recipient_address',
                'type' => 'text',
                'label' => __('statamic-funnels::nodes.field_recipient_address'),
                'instructions' => __('statamic-funnels::nodes.field_recipient_address_help'),
            ],
            [
                'handle' => 'subject_override',
                'type' => 'text',
                'label' => __('statamic-funnels::nodes.field_subject_override'),
                'instructions' => __('statamic-funnels::nodes.field_subject_override_help'),
            ],
        ];
    }

    /**
     * Die Verzoegerung eines Knotens in Sekunden. Null heisst sofort.
     *
     * @param  array<string, mixed>  $config
     */
    public static function delaySeconds(array $config): int
    {
        $amount = max(0, (int) ($config['delay_amount'] ?? 0));

        if ($amount === 0) {
            return 0;
        }

        return match ((string) ($config['delay_unit'] ?? self::UNIT_MINUTES)) {
            self::UNIT_DAYS => $amount * 86400,
            self::UNIT_HOURS => $amount * 3600,
            default => $amount * 60,
        };
    }
}
