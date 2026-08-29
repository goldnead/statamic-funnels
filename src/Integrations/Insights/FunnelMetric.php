<?php

namespace Goldnead\StatamicFunnels\Integrations\Insights;

use Goldnead\StatamicFunnels\Support\StepStats;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\TableMetric;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What every funnel figure has in common.
 *
 * **The relationship to {@see StepStats} is the whole reason this directory
 * exists.** StepStats answers where people stop
 * — per step, per A/B variant, over the entire life of a funnel. It has no
 * period and needs none: a drop-off at step three is a property of the funnel,
 * not of last week. What it cannot answer is whether anything is changing, and
 * that is the question a dashboard is opened for. So the numbers here are the
 * same facts with a time axis on them, and nothing of StepStats is duplicated
 * or moved: it keeps its per-step detail, these keep the trend. Two questions,
 * two places, no third copy of the arithmetic.
 *
 * The queries go through {@see TableMetric}, which is the analytics addon's own
 * base for anything counted out of one table with a timestamp on it. Windowing,
 * bucketing in three SQL dialects and splitting without dropping the null rows
 * all live up there and are deliberately not written again down here — a second
 * copy of a bucket expression is a second thing to get wrong on MySQL.
 *
 * Nothing in this directory is imported from the sibling beyond that base, the
 * contract and its two value objects, and none of these classes is ever loaded
 * unless the sibling has announced itself (see the guard in the ServiceProvider).
 * Hence `suggest` in composer.json and never `require`.
 */
abstract class FunnelMetric extends TableMetric
{
    public function group(): string
    {
        return __('statamic-funnels::messages.metric_group');
    }

    /**
     * The window, and only rows that have actually happened.
     *
     * The `whereNotNull` is the load-bearing half and it is easy to read as
     * redundant, because the two `where` comparisons already exclude a null.
     * They only do so **while there are bounds**. The `all` preset carries no
     * from and no to, both `when()` clauses fall away, and a metric counting on
     * `completed_at` would then count every walk ever started — including the
     * ones that never finished, which is the exact opposite of what it claims
     * to measure. A figure that is right for every period except the widest one
     * is the quiet kind of wrong: nobody re-adds the total.
     */
    protected function inPeriod(MetricQuery $query, ?string $column = null): Builder
    {
        $column ??= $this->timestamp();

        return parent::inPeriod($query, $column)->whereNotNull($column);
    }

    /**
     * Funnel ids turned into the names people gave them.
     *
     * On the base rather than on the one metric that splits this way, because
     * "what a funnel is called" is a fact about this addon and not about a
     * particular figure — the next metric to offer the split has to name them
     * identically or two screens disagree about the same row.
     *
     * One query for all of them, not one per row: a split of twenty funnels
     * would otherwise be twenty-one round trips to render one small table.
     *
     * A funnel whose row is gone keeps its id as its label. It cannot normally
     * happen — deleting a funnel cascades its visits away — but a report that
     * silently dropped the row would disagree with its own total, and an id is
     * at least something a person can look up.
     *
     * @param  array<int, array{key: string|null, value: int|float}>  $rows
     * @return array<int, array{key: string|null, label: string, value: int|float}>
     */
    protected function labelledByFunnel(array $rows): array
    {
        $ids = array_values(array_filter(
            array_column($rows, 'key'),
            fn ($key): bool => $key !== null,
        ));

        $titles = $ids === []
            ? []
            : DB::table('funnels')->whereIn('id', $ids)->pluck('title', 'id')->all();

        return array_map(fn (array $row): array => [
            'key' => $row['key'],
            'label' => $row['key'] === null
                ? $this->missingLabel('funnel_id')
                : (string) ($titles[$row['key']] ?? $row['key']),
            'value' => $row['value'],
        ], $rows);
    }

    /**
     * What to call the rows that have no value in the dimension they are split by.
     *
     * Per dimension, because "no funnel" and "no event" read differently and a
     * shared dash tells a reader nothing about which of the two happened.
     */
    protected function missingLabel(string $dimension): string
    {
        return __('statamic-funnels::messages.metric_no_'.$dimension);
    }
}
