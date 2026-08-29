<?php

namespace Goldnead\StatamicFunnels\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many walks began.
 *
 * Counted on `created_at`, which for a visit is when somebody first stood on
 * the first step — the row is written at that moment and never earlier. One row
 * per visitor per funnel: a person who reloads the offer page four times began
 * one walk, which is the same rule `StepStats` counts by and the reason a rate
 * built on page loads flatters every step with a reload button on it.
 */
class Visits extends FunnelMetric implements HasBreakdowns
{
    protected function table(): string
    {
        return 'funnel_visits';
    }

    protected function timestamp(): string
    {
        return 'created_at';
    }

    public function handle(): string
    {
        return 'funnels.visits';
    }

    public function label(): string
    {
        return __('statamic-funnels::messages.metric_visits');
    }

    public function description(): ?string
    {
        return __('statamic-funnels::messages.metric_visits_description');
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

    public function breakdowns(): array
    {
        return ['funnel_id' => __('statamic-funnels::messages.metric_breakdown_funnel')];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available() || $dimension !== 'funnel_id') {
            return [];
        }

        return $this->labelledByFunnel(
            $this->splitByColumn($this->inPeriod($query), $query, 'funnel_id', 'count(*)', $limit),
        );
    }
}
