<?php

namespace Goldnead\StatamicFunnels\Nodes;

use Goldnead\StatamicFunnels\Http\Middleware\TrackPage;
use Goldnead\StatamicFunnels\Integrations\ExternalSteps;

/**
 * A step that happens outside the funnel.
 *
 * A funnel can describe a journey that is not made of its own pages: the
 * opt-in page is a page of the site, the course is a mail automation. This
 * step is how such a part shows up on the map and in the numbers without the
 * funnel rendering anything.
 *
 * Two ways to reach it, both optional and both generic:
 *
 *  - **`path`**: a page of the site. The route carries the
 *    `statamic-funnels.track` middleware, and opening the page enters the step
 *    ({@see TrackPage}).
 *  - **`automation`**: the handle of an automation. The walk enters the step
 *    when the step before hands over, and leaves it when the automation ran to
 *    its end for the visitor's address ({@see ExternalSteps}).
 *
 * It is not a page: no slug, no URL under `/f`, nothing to render.
 */
class ExternalStep extends StepType
{
    public static function handle(): string
    {
        return 'external';
    }

    public static function kind(): string
    {
        return 'page';
    }

    public static function label(): string
    {
        return __('statamic-funnels::nodes.external_label');
    }

    public static function description(): string
    {
        return __('statamic-funnels::nodes.external_description');
    }

    public static function icon(): string
    {
        return 'link';
    }

    public static function schema(): array
    {
        return [
            ['handle' => 'path', 'type' => 'text', 'label' => __('statamic-funnels::nodes.field_external_path'), 'instructions' => __('statamic-funnels::nodes.field_external_path_help')],
            ['handle' => 'automation', 'type' => 'text', 'label' => __('statamic-funnels::nodes.field_external_automation'), 'instructions' => __('statamic-funnels::nodes.field_external_automation_help')],
        ];
    }
}
