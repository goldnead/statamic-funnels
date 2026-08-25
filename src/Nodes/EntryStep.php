<?php

namespace Goldnead\StatamicFunnels\Nodes;

/**
 * Where the walk starts.
 *
 * One per funnel, and the only step whose URL is the funnel's own: `/f/{handle}`
 * rather than `/f/{handle}/{slug}`. A visitor should not have to know they are
 * in a funnel to be in one.
 */
class EntryStep extends StepType
{
    public static function handle(): string
    {
        return 'entry';
    }

    public static function kind(): string
    {
        return 'entry';
    }

    public static function label(): string
    {
        return __('statamic-funnels::nodes.entry_label');
    }

    public static function description(): string
    {
        return __('statamic-funnels::nodes.entry_description');
    }

    public static function icon(): string
    {
        return 'sign-post';
    }

    public static function isPage(): bool
    {
        return true;
    }

    public static function isUnique(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return self::pageSchema();
    }
}
