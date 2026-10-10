<?php

namespace Goldnead\StatamicFunnels\Http\Controllers\Web;

use Goldnead\StatamicFunnels\Integrations\LeadMagnetsBridge;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\Embed;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Illuminate\Http\Request;

/**
 * Back from the confirmation mail.
 *
 * lead-magnets redirects here after the click (the URL was handed to it as the
 * return URL, signed). The `signed` middleware on the route proves the link
 * was issued by this application; it does **not** prove the address was
 * confirmed, so the grant is asked again before anything moves. Two links, two
 * different facts.
 *
 * ## Which browser is this?
 *
 * The click often happens somewhere else than the form: the mail opens in a
 * mail app's own browser. The visit is named in the signed URL, so the walk
 * can be picked up regardless, and the browser gets the visit's cookie if it
 * has none. A browser that already carries *another* walk keeps it, and the
 * visit is lent for the one page that follows, the way a return from the payment
 * provider does it ({@see FunnelController::returned()}): a forwarded link must
 * not replace somebody's walk.
 *
 * ## Where it may send anybody
 *
 * Only to a step of the funnel in the URL. The target is never read from the
 * request.
 */
class LeadMagnetResumeController
{
    public function __construct(
        protected FunnelWalk $walk,
        protected LeadMagnetsBridge $bridge,
    ) {}

    public function __invoke(Request $request, string $funnel, string $nodeKey, int $visit)
    {
        $model = Funnel::with(['steps', 'edges'])->where('handle', $funnel)->first();

        abort_unless($model && $model->published, 404);

        $step = $model->stepByKey($nodeKey);

        abort_unless($step && $step->type === 'lead_magnet' && ! $step->disabled, 404);

        $walk = FunnelVisit::query()->where('funnel_id', $model->id)->whereKey($visit)->first();

        abort_unless($walk !== null, 404);

        $this->bind($request, $walk);

        $outcome = $this->bridge->resume($model, $step, $walk);

        // Confirmed: on to the next step, or to the entry when the walk ends.
        // Anything else: back to the waiting page, which says why.
        $target = $outcome['status'] === LeadMagnetsBridge::STATUS_CONFIRMED
            ? ($outcome['next'] ?? null)
            : $step;

        $response = $target
            ? redirect()->route('statamic-funnels.step', [$model->handle, $target->slug])
            : redirect()->route('statamic-funnels.entry', $model->handle);

        return Embed::protect($response, $model, false);
    }

    /** Let this browser continue the visit named in the link. */
    protected function bind(Request $request, FunnelVisit $visit): void
    {
        if (! $this->walk->hasCookie()) {
            $this->walk->queueCookie((string) $visit->token);

            return;
        }

        if ($request->cookie(FunnelWalk::COOKIE) !== $visit->token && $request->hasSession()) {
            $request->session()->flash(FunnelWalk::RETURNED, $visit->token);
        }
    }
}
