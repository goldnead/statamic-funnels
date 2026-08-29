<?php

namespace Goldnead\StatamicFunnels\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Of the walks that began here, the share that reached the end.
 *
 * **A cohort on `created_at`, not a division of two periods.** The visits that
 * started inside the window are the denominator, and the numerator is however
 * many of *those same walks* carry a `completed_at` — whenever it was set. The
 * alternative, completions-in-period over visits-in-period, mixes two axes and
 * can print more than 100 % on a quiet week that happened to close a lot of old
 * walks, which is a number no reader can do anything with.
 *
 * Three reasons this is the right shape here, where a revenue report would
 * choose the other one:
 *
 * 1. `StepStats` in this addon already counts cohort-wise — visitors who
 *    entered a step against visitors who carried on from it. A dashboard that
 *    defined the same word differently would be two definitions of "conversion"
 *    in one addon.
 * 2. A walk lasts minutes, not months. The cohort closes almost as soon as it
 *    opens, so the retroactive drift a cohort normally suffers from is small.
 * 3. It cannot exceed its own denominator, so the figure beside it always
 *    explains it.
 *
 * The drift is real and worth saying out loud: **a very fresh window can still
 * rise**, because a walk that began an hour ago may finish tonight. A rate for
 * today read at noon is a lower bound.
 *
 * Null rather than zero when nothing began. "0 % completed" is a statement about
 * walks that did not happen, and it would sit on the screen next to a visit
 * count of zero that contradicts it.
 */
class CompletionRate extends FunnelMetric
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
        return 'funnels.completion_rate';
    }

    public function label(): string
    {
        return __('statamic-funnels::messages.metric_completion_rate');
    }

    public function description(): ?string
    {
        return __('statamic-funnels::messages.metric_completion_rate_description');
    }

    public function unit(): string
    {
        return Unit::PERCENT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        $begonnen = (int) $this->inPeriod($query)->count();

        if ($begonnen <= 0) {
            return null;
        }

        $abgeschlossen = (int) $this->inPeriod($query)->whereNotNull('completed_at')->count();

        // One decimal. A completion rate is read to compare weeks, and
        // "43.7215 %" asserts a precision that eleven walks cannot carry.
        return round($abgeschlossen / $begonnen * 100, 1);
    }

    /**
     * A rate per bucket, and only where there is something to divide by.
     *
     * A bucket in which no walk began is left out rather than shown as zero —
     * the same rule as the headline, applied per column. The chart then draws
     * no bar for that day, which is correct: a rate needs a denominator and
     * that day has none.
     *
     * Both halves are bucketed on `created_at`, the cohort's own date. Bucketing
     * the completions on `completed_at` would put a walk's two halves in two
     * different columns and produce rates over 100 % in one of them.
     */
    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        $begonnen = $this->bucketed($this->inPeriod($query), $query, 'count(*)');

        $abgeschlossen = $this->bucketed(
            $this->inPeriod($query)->whereNotNull('completed_at'),
            $query,
            'count(*)',
        );

        $buckets = [];

        foreach ($begonnen as $bucket => $anzahl) {
            if ((int) $anzahl > 0) {
                $buckets[$bucket] = round((int) ($abgeschlossen[$bucket] ?? 0) / (int) $anzahl * 100, 1);
            }
        }

        ksort($buckets);

        return $buckets;
    }
}
