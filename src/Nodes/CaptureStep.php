<?php

namespace Goldnead\StatamicFunnels\Nodes;

/**
 * A page with a form on it.
 *
 * The form is a **native Statamic form**, not one this addon invented. A site
 * already has forms, with fields, validation, email notifications and a
 * submissions screen; a funnel that grew its own would be a second, worse copy
 * of all of it — and the submissions would live somewhere nobody looks.
 */
class CaptureStep extends StepType
{
    /** E-Mail, und der Name freiwillig. Was dieser Schritt immer schon konnte. */
    public const BILLING_MINIMAL = 'minimal';

    /** Der Name ist Pflicht. */
    public const BILLING_NAME = 'name';

    /**
     * Name und Anschrift sind Pflicht.
     *
     * Der Fall, ohne den ueber 250 Euro **gar keine Rechnung entsteht**: § 33
     * UStDV laesst die Kleinbetragsrechnung nur darunter zu, darueber verlangt
     * § 14 UStG Name und Anschrift des Empfaengers. Der `InvoiceWriter`
     * verweigert dann die Rechnung, statt zu raten — belegt an Payment 23 ueber
     * 347 Euro, das nur eine Warnung im Log hinterliess.
     */
    public const BILLING_FULL = 'full';

    public static function handle(): string
    {
        return 'capture';
    }

    /** @return list<string> */
    public static function billingModes(): array
    {
        return [self::BILLING_MINIMAL, self::BILLING_NAME, self::BILLING_FULL];
    }

    public static function kind(): string
    {
        return 'page';
    }

    public static function label(): string
    {
        return __('statamic-funnels::nodes.capture_label');
    }

    public static function description(): string
    {
        return __('statamic-funnels::nodes.capture_description');
    }

    public static function icon(): string
    {
        return 'forms';
    }

    public static function isPage(): bool
    {
        return true;
    }

    public static function outputs(): array
    {
        return [['handle' => 'default', 'label' => __('statamic-funnels::nodes.output_submitted')]];
    }

    public static function schema(): array
    {
        return array_merge([
            ['handle' => 'form', 'type' => 'form', 'label' => __('statamic-funnels::nodes.field_form'), 'instructions' => __('statamic-funnels::nodes.field_form_help')],
            [
                'handle' => 'billing',
                'type' => 'select',
                'label' => __('statamic-funnels::nodes.field_billing'),
                'instructions' => __('statamic-funnels::nodes.field_billing_help'),
                'default' => self::BILLING_MINIMAL,
                'options' => array_map(
                    fn (string $mode) => ['value' => $mode, 'label' => __('statamic-funnels::nodes.billing_'.$mode)],
                    self::billingModes(),
                ),
            ],
        ], self::pageSchema());
    }
}
