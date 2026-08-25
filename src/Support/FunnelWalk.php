<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Events\FunnelCompleted;
use Goldnead\StatamicFunnels\Events\FunnelStepEntered;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Where a visitor is, and how they got there.
 *
 * The token lives in the visitor's own cookie and identifies a *walk*, not a
 * person: a funnel has to work before anybody has said who they are, and most
 * visitors never do. Nothing here needs a login.
 */
class FunnelWalk
{
    public const COOKIE = 'statamic_funnel';

    public function __construct(protected Request $request) {}

    /** The walk this request belongs to, started if it is the first step. */
    public function visit(Funnel $funnel): FunnelVisit
    {
        $token = $this->token();

        return FunnelVisit::firstOrCreate(
            ['funnel_id' => $funnel->id, 'token' => $token],
            ['current_node_key' => $funnel->entryStep()?->node_key],
        );
    }

    /**
     * The token for this browser.
     *
     * Read from the cookie if there is one, otherwise made up here and queued
     * on the response. Not a session id: a walk that survives a browser restart
     * is the ordinary case, because the second half of a funnel usually arrives
     * by email.
     */
    public function token(): string
    {
        $existing = $this->request->cookie(self::COOKIE);

        if (is_string($existing) && preg_match('/^[A-Za-z0-9]{32}$/', $existing)) {
            return $existing;
        }

        $token = Str::random(32);
        cookie()->queue(cookie(self::COOKIE, $token, 60 * 24 * 30, null, null, null, true, false, 'Lax'));

        return $token;
    }

    /** Record arrival at a step, once per step per walk. */
    public function enter(FunnelVisit $visit, FunnelStep $step): void
    {
        $visit->forceFill(['current_node_key' => $step->node_key])->save();

        // Once. A visitor who reloads a page has not entered it twice, and a
        // drop-off report built on repeat views is a report about refreshing.
        if ($visit->hasReached($step->node_key)) {
            return;
        }

        $visit->record($step->node_key, FunnelStepEvent::ENTERED);

        FunnelStepEntered::dispatch($visit, $step);
    }

    /** Move on, and say by which way out. */
    public function advance(FunnelVisit $visit, FunnelStep $from, string $output, string $event, array $payload = []): ?FunnelStep
    {
        $visit->record($from->node_key, $event, $payload);

        $next = $visit->funnel->nextStep($from->node_key, $output);

        if (! $next) {
            // Nothing beyond this way out. That is a legitimate shape — a
            // declined branch that simply stops — and it ends the walk rather
            // than erroring.
            $this->complete($visit, $from);

            return null;
        }

        return $next;
    }

    public function complete(FunnelVisit $visit, FunnelStep $step): void
    {
        if ($visit->completed_at) {
            return;
        }

        $visit->forceFill(['completed_at' => now()])->save();
        $visit->record($step->node_key, FunnelStepEvent::COMPLETED);

        FunnelCompleted::dispatch($visit->fresh() ?? $visit);
    }
}
