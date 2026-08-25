<?php

namespace Goldnead\StatamicFunnels\Nodes;

use Goldnead\StatamicFunnels\Support\Countdown;

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
        return array_merge([
            ['handle' => 'offer', 'type' => 'offer', 'label' => __('statamic-funnels::nodes.field_offer'), 'instructions' => __('statamic-funnels::nodes.field_offer_help')],
            // A deadline, and one that actually holds: past it the step refuses
            // to be accepted, not merely stops drawing a clock.
            ['handle' => 'countdown', 'type' => 'select', 'options' => Countdown::kinds(), 'label' => __('statamic-funnels::nodes.field_countdown'), 'instructions' => __('statamic-funnels::nodes.field_countdown_help')],
            ['handle' => 'countdown_until', 'type' => 'text', 'label' => __('statamic-funnels::nodes.field_countdown_until'), 'instructions' => __('statamic-funnels::nodes.field_countdown_until_help')],
            ['handle' => 'countdown_hours', 'type' => 'text', 'label' => __('statamic-funnels::nodes.field_countdown_hours'), 'instructions' => __('statamic-funnels::nodes.field_countdown_hours_help')],
        ], self::pageSchema());
    }
}
