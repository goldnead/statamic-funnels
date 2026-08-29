<?php

namespace Goldnead\StatamicFunnels\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many walks reached the end.
 *
 * On `completed_at`, so a walk that began in July and finished in August
 * belongs to August — the month it finished is the month something was
 * achieved. That makes this figure deliberately **not** the numerator of
 * {@see CompletionRate}, which counts the walks that *began* in the window and
 * asks how many of those ever got to the end. Two honest questions with two
 * different answers, and the difference is visible the moment a walk crosses a
 * period boundary.
 *
 * Reading the two side by side: this one says what was finished here, the rate
 * says how good the period's own traffic was.
 */
class Completed extends FunnelMetric
{
    protected function table(): string
    {
        return 'funnel_visits';
    }

    protected function timestamp(): string
    {
        return 'completed_at';
    }

    public function handle(): string
    {
        return 'funnels.completed';
    }

    public function label(): string
    {
        return __('statamic-funnels::messages.metric_completed');
    }

    public function description(): ?string
    {
        return __('statamic-funnels::messages.metric_completed_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return (int) $this->inPeriod($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured): int => (int) $measured,
            $this->bucketed($this->inPeriod($query), $query, 'count(*)'),
        );
    }
}
