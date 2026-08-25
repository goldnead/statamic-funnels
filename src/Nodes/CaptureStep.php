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
    public static function handle(): string
    {
        return 'capture';
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
        ], self::pageSchema());
    }
}
