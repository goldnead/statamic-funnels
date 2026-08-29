<?php

namespace Goldnead\StatamicFunnels\Integrations\Insights;

use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Support\StepStats;
use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Every step anybody took, as it was recorded.
 *
 * On `created_at`, because the table has no other timestamp — and here that is
 * not a compromise. The row is written in the request that performs the step,
 * so "when it happened" and "when it was noted" are the same instant. That is
 * unlike a payment, which is paid on the 30th and recorded on the 1st, and it
 * is worth saying rather than leaving somebody to wonder which one this is.
 *
 * Deliberately events and not visitors: this is the volume of movement through
 * the funnels, and the split by kind is where it becomes readable. The
 * per-visitor reading of the same rows — how many people got past step three —
 * is {@see StepStats}, which counts distinct visits and is not repeated here.
 */
class StepEvents extends FunnelMetric implements HasBreakdowns
{
    /**
     * The kinds this addon writes, and what to call them.
     *
     * A fixed map rather than a translation key built from the column value:
     * the column is a free string, and assembling `metric_event_`.$row into a
     * lookup would turn whatever is in the database into a translation key. A
     * kind that is not in this list keeps its raw value — visible and odd,
     * which is what an unexpected value should be, rather than absent.
     *
     * @var array<string, string>
     */
    protected const EVENT_LABELS = [
        FunnelStepEvent::ENTERED => 'metric_event_entered',
        FunnelStepEvent::SUBMITTED => 'metric_event_submitted',
        FunnelStepEvent::ACCEPTED => 'metric_event_accepted',
        FunnelStepEvent::DECLINED => 'metric_event_declined',
        FunnelStepEvent::COMPLETED => 'metric_event_completed',
    ];

    protected function table(): string
    {
        return 'funnel_step_events';
    }

    protected function timestamp(): string
    {
        return 'created_at';
    }

    public function handle(): string
    {
        return 'funnels.step_events';
    }

    public function label(): string
    {
        return __('statamic-funnels::messages.metric_step_events');
    }

    public function description(): ?string
    {
        return __('statamic-funnels::messages.metric_step_events_description');
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
        return ['event' => __('statamic-funnels::messages.metric_breakdown_event')];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available() || $dimension !== 'event') {
            return [];
        }

        $rows = $this->splitByColumn($this->inPeriod($query), $query, 'event', 'count(*)', $limit);

        return array_map(fn (array $row): array => [
            'key' => $row['key'],
            'label' => $this->eventLabel($row['key']),
            'value' => $row['value'],
        ], $rows);
    }

    /** The words for one kind of step, or the raw value where there are none. */
    protected function eventLabel(?string $event): string
    {
        if ($event === null) {
            return $this->missingLabel('event');
        }

        return isset(self::EVENT_LABELS[$event])
            ? __('statamic-funnels::messages.'.self::EVENT_LABELS[$event])
            : $event;
    }
}
