<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Illuminate\Support\Facades\DB;

/**
 * Where people stop.
 *
 * The one number a funnel is actually judged by, and the one this addon could
 * not answer until now: not how many visits there were, but at which step they
 * ran out. The rows to answer it were already being written — `funnel_step_events`
 * has one per step per visitor — so this is arithmetic, not new plumbing.
 *
 * Counted per **visit**, not per event. Somebody who reloads the offer page four
 * times looked at it once, and a rate built on page loads flatters every step
 * with a reload button on it.
 */
class StepStats
{
    /**
     * @return array<string, array{visits: int, continued: int, accepted: int, declined: int, submitted: int, rate: float|null}>
     */
    public static function forFunnel(Funnel $funnel): array
    {
        $visitIds = $funnel->visits()->select('id');

        /** @var array<string, array<string, int>> $counts */
        $counts = [];

        $rows = FunnelStepEvent::query()
            ->whereIn('visit_id', $visitIds)
            ->select('node_key', 'event', DB::raw('COUNT(DISTINCT visit_id) as visitors'))
            ->groupBy('node_key', 'event')
            ->get();

        foreach ($rows as $row) {
            $counts[$row->node_key][$row->event] = (int) $row->visitors;
        }

        // Where each step leads. "Continued" means a visitor who was on this
        // step also turned up on one of the steps it points at — which is the
        // honest reading of moving on, and the reason it is measured from the
        // edges rather than from a timestamp comparison: a visitor who goes
        // back and forth has still continued.
        $targets = [];

        foreach ($funnel->edges as $edge) {
            $targets[$edge->from_node_key][] = $edge->to_node_key;
        }

        $out = [];

        foreach ($funnel->steps as $step) {
            $key = $step->node_key;
            $visits = $counts[$key][FunnelStepEvent::ENTERED] ?? 0;

            $leadsOn = ($targets[$key] ?? []) !== [];
            $continued = self::continuedFrom($funnel, $key, $targets[$key] ?? []);

            $out[$key] = [
                'visits' => $visits,
                'continued' => $continued,
                // Whether there is anywhere to continue *to*. A thank-you page
                // at the end of the walk is not converting at 0 %, it is the
                // end — and "0 %" on the last card reads as a broken step.
                'terminal' => ! $leadsOn,
                'accepted' => $counts[$key][FunnelStepEvent::ACCEPTED] ?? 0,
                'declined' => $counts[$key][FunnelStepEvent::DECLINED] ?? 0,
                'submitted' => $counts[$key][FunnelStepEvent::SUBMITTED] ?? 0,
                // Null rather than zero when nobody has been here, and null
                // again where there is nowhere to go. A step that has never been
                // seen has no conversion rate, and neither does the last one.
                'rate' => $visits > 0 && $leadsOn ? round($continued / $visits * 100, 1) : null,
            ];
        }

        return $out;
    }

    /**
     * How many visitors who reached this step also reached one it leads to.
     *
     * @param  list<string>  $targets
     */
    protected static function continuedFrom(Funnel $funnel, string $key, array $targets): int
    {
        if ($targets === []) {
            return 0;
        }

        $visitIds = $funnel->visits()->select('id');

        $here = FunnelStepEvent::query()
            ->whereIn('visit_id', $visitIds)
            ->where('node_key', $key)
            ->where('event', FunnelStepEvent::ENTERED)
            ->select('visit_id');

        return FunnelStepEvent::query()
            ->whereIn('visit_id', $here)
            ->whereIn('node_key', $targets)
            ->where('event', FunnelStepEvent::ENTERED)
            ->distinct()
            ->count('visit_id');
    }
}
