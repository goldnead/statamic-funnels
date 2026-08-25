<?php

namespace Goldnead\StatamicFunnels\Nodes;

/** A plain page on the way: a thank-you, an explanation, a delivery notice. */
class PageStep extends StepType
{
    public static function handle(): string
    {
        return 'page';
    }

    public static function kind(): string
    {
        return 'page';
    }

    public static function label(): string
    {
        return __('statamic-funnels::nodes.page_label');
    }

    public static function description(): string
    {
        return __('statamic-funnels::nodes.page_description');
    }

    public static function icon(): string
    {
        return 'file-content-list';
    }

    public static function isPage(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            ['handle' => 'template', 'type' => 'text', 'label' => __('statamic-funnels::nodes.field_template'), 'instructions' => __('statamic-funnels::nodes.field_template_help')],
            ['handle' => 'headline', 'type' => 'text', 'label' => __('statamic-funnels::nodes.field_headline')],
            ['handle' => 'body', 'type' => 'textarea', 'label' => __('statamic-funnels::nodes.field_body')],
        ];
    }
}
