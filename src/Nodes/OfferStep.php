<?php

namespace Goldnead\StatamicFunnels\Nodes;

/**
 * The step where money can change hands.
 *
 * Two ways out, and both are ordinary: somebody buys, or they do not. A funnel
 * whose declined branch leads nowhere is a funnel that gives up on most of its
 * visitors, so the canvas draws both and the editor nags about neither.
 */
class OfferStep extends StepType
{
    public static function handle(): string
    {
        return 'offer';
    }

    public static function kind(): string
    {
        return 'offer';
    }

    public static function label(): string
    {
        return __('statamic-funnels::nodes.offer_label');
    }

    public static function description(): string
    {
        return __('statamic-funnels::nodes.offer_description');
    }

    public static function icon(): string
    {
        return 'money-cashier-price-tag';
    }

    public static function isPage(): bool
    {
        return true;
    }

    public static function outputs(): array
    {
        return [
            ['handle' => 'accepted', 'label' => __('statamic-funnels::nodes.output_accepted')],
            ['handle' => 'declined', 'label' => __('statamic-funnels::nodes.output_declined')],
        ];
    }

    public static function schema(): array
    {
        return [
            ['handle' => 'offer', 'type' => 'offer', 'label' => __('statamic-funnels::nodes.field_offer'), 'instructions' => __('statamic-funnels::nodes.field_offer_help')],
            ['handle' => 'template', 'type' => 'text', 'label' => __('statamic-funnels::nodes.field_template'), 'instructions' => __('statamic-funnels::nodes.field_template_help')],
            ['handle' => 'headline', 'type' => 'text', 'label' => __('statamic-funnels::nodes.field_headline')],
            ['handle' => 'body', 'type' => 'textarea', 'label' => __('statamic-funnels::nodes.field_body')],
        ];
    }
}
