<?php

namespace Goldnead\StatamicFunnels\Nodes;

/**
 * The end of the walk.
 *
 * Marks the visit complete, which is what every "how many finished" question
 * counts, and optionally grants what was bought.
 */
class FinishStep extends StepType
{
    public static function handle(): string
    {
        return 'finish';
    }

    public static function kind(): string
    {
        return 'finish';
    }

    public static function label(): string
    {
        return __('statamic-funnels::nodes.finish_label');
    }

    public static function description(): string
    {
        return __('statamic-funnels::nodes.finish_description');
    }

    public static function icon(): string
    {
        return 'flag';
    }

    public static function isPage(): bool
    {
        return true;
    }

    public static function outputs(): array
    {
        // Nothing leaves the end.
        return [];
    }

    public static function schema(): array
    {
        return array_merge(self::pageSchema(), [
            ['handle' => 'redirect', 'type' => 'text', 'label' => __('statamic-funnels::nodes.field_redirect'), 'instructions' => __('statamic-funnels::nodes.field_redirect_help')],
        ]);
    }
}
