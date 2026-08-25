<?php

namespace Goldnead\StatamicFunnels\Nodes;

/**
 * What a step can be.
 *
 * Deliberately few. A funnel that needs a dozen node types is an automation
 * wearing a costume; this is a path with pages on it, and the shape of the path
 * is the product.
 */
abstract class StepType
{
    /** The handle stored in `funnel_steps.type`. */
    abstract public static function handle(): string;

    /** Which group of the node library it appears in. */
    abstract public static function kind(): string;

    abstract public static function label(): string;

    abstract public static function description(): string;

    /** A real icon name from `@statamic/cms`; an invented one renders nothing. */
    abstract public static function icon(): string;

    /**
     * The ways out of this step.
     *
     * Evaluated by the shared canvas, which draws one handle per output and
     * lays the branches out beneath them. `default` is a single continuation.
     *
     * @return list<array{handle: string, label?: string}>
     */
    public static function outputs(): array
    {
        return [['handle' => 'default']];
    }

    /**
     * The fields shown when the step is selected, as the config panel reads
     * them.
     *
     * @return list<array<string, mixed>>
     */
    public static function schema(): array
    {
        return [];
    }

    /**
     * The fields every step with a URL has.
     *
     * A step shows a **page**, and there are two ways to say what that page is:
     * point at a Statamic entry, or write a headline and a bit of text. The
     * entry wins, and it is the one that matters — a landing page belongs in the
     * page builder the site already has, not in a textarea inside an addon.
     *
     * @return list<array<string, mixed>>
     */
    protected static function pageSchema(): array
    {
        return [
            ['handle' => 'entry', 'type' => 'entry', 'label' => __('statamic-funnels::nodes.field_entry'), 'instructions' => __('statamic-funnels::nodes.field_entry_help')],
            ['handle' => 'template', 'type' => 'text', 'label' => __('statamic-funnels::nodes.field_template'), 'instructions' => __('statamic-funnels::nodes.field_template_help')],
            ['handle' => 'headline', 'type' => 'text', 'label' => __('statamic-funnels::nodes.field_headline')],
            ['handle' => 'body', 'type' => 'textarea', 'label' => __('statamic-funnels::nodes.field_body')],
        ];
    }

    /** Whether a visitor stands on this step, and it therefore needs a URL. */
    public static function isPage(): bool
    {
        return false;
    }

    /** At most one of these per funnel. */
    public static function isUnique(): bool
    {
        return false;
    }
}
