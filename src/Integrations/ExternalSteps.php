<?php

namespace Goldnead\StatamicFunnels\Integrations;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Nodes\ExternalStep;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Support\ProbePreview;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What happens outside the funnel, and the funnel hears of it.
 *
 * A journey that starts on a page of the site (an `external` step, see
 * {@see ExternalStep}) has no form step of its
 * own: the address is given to lead-magnets by the site's own form, and the
 * course that follows is an automation. Two siblings say what happened, and
 * this class turns it into the walk of the visitor who was counted on the
 * page:
 *
 *  1. **lead-magnets** asked for a resource (`ResourceRequested`) or proved
 *     the address (`ResourceConfirmed`). The step that answers is a
 *     `lead_magnet` step whose only way in is an `external` step. Requested
 *     and confirmed are written once each, the way the lead magnet step of the
 *     funnel's own forms writes them ({@see LeadMagnetsBridge}).
 *  2. **automations** ran a run to its end (`AutomationRunFinished`). The step
 *     that answers is an `external` step with that automation's handle. The
 *     walk leaves it, and ends if an end step follows.
 *
 * ## Who is the visitor
 *
 * The request of a form is the browser of the visitor, so its cookie finds the
 * walk. Everything after that (the click in the mail, the end of a run days
 * later) has no browser to ask, and finds the walk by the **address** the
 * request put on it. A person who was never counted (no cookie, a consent
 * that was not given, another device) has no walk, and none is made up: a
 * walk without its first step would have visitors that never visited.
 *
 * ## Nothing here may break the sibling
 *
 * The events are fired inside a form request, a mail click and the engine of
 * a run. Every failure is caught and logged.
 *
 * The event classes are named as strings: neither sibling is a requirement of
 * this addon, and a listener for an event that never fires costs nothing.
 */
class ExternalSteps
{
    public const REQUESTED = 'Goldnead\\LeadMagnets\\Events\\ResourceRequested';

    public const CONFIRMED = 'Goldnead\\LeadMagnets\\Events\\ResourceConfirmed';

    public const AUTOMATION_FINISHED = 'Goldnead\\StatamicAutomations\\Events\\AutomationRunFinished';

    public function __construct(protected FunnelWalk $walk) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(self::REQUESTED, fn (object $event) => $this->guard('requested', fn () => $this->requested($event->grant)));
        $events->listen(self::CONFIRMED, fn (object $event) => $this->guard('confirmed', fn () => $this->confirmed($event->grant)));
        $events->listen(self::AUTOMATION_FINISHED, fn (object $event) => $this->guard('automation', fn () => $this->automationFinished($event)));
    }

    protected function guard(string $what, callable $do): void
    {
        try {
            $do();
        } catch (Throwable $e) {
            report($e);
            Log::warning('statamic-funnels: an outside step could not be recorded ('.$what.').', ['error' => $e->getMessage()]);
        }
    }

    /** Somebody asked lead-magnets for a resource. */
    protected function requested(object $grant): void
    {
        if (ProbePreview::active() || $this->ownRequest($grant)) {
            return;
        }

        $email = $this->email($grant);

        foreach ($this->leadMagnetSteps((string) ($grant->resource->handle ?? '')) as $step) {
            $visit = $this->visitOfRequest($step->funnel, $email);

            if ($visit === null) {
                continue;
            }

            $meta = (array) ($visit->meta ?? []);
            $meta['lead_magnet'][$step->node_key] = ['grant_id' => $grant->id, 'resource' => $grant->resource->handle];

            $visit->forceFill(['email' => $visit->email ?: $email, 'meta' => $meta])->save();

            $this->walk->enter($visit, $step);

            $visit->recordOnce($step->node_key, FunnelStepEvent::LEAD_MAGNET_REQUESTED, [
                'resource' => $grant->resource->handle,
                'grant' => $grant->id,
            ]);
        }
    }

    /** The address was proven (or nothing had to be proven). */
    protected function confirmed(object $grant): void
    {
        if (ProbePreview::active() || $this->ownRequest($grant)) {
            return;
        }

        $email = $this->email($grant);

        foreach ($this->leadMagnetSteps((string) ($grant->resource->handle ?? '')) as $step) {
            $visit = $this->visitOfAddress($step->funnel, $email, $step->node_key, (int) $grant->id);

            if ($visit === null) {
                continue;
            }

            // Not asked on this walk (the request came before there was a
            // counted page, or the first row was lost): arriving is part of it.
            $this->walk->enter($visit, $step);

            if (! $visit->recordOnce($step->node_key, FunnelStepEvent::LEAD_MAGNET_CONFIRMED, ['grant' => $grant->id])) {
                continue;
            }

            $next = $this->walk->advance($visit, $step, 'default', FunnelStepEvent::LEAD_MAGNET_CONFIRMED, [], recorded: true);

            if ($next !== null) {
                $this->walk->reach($visit, $next);
            }
        }
    }

    /** A run of an automation went to its end. */
    protected function automationFinished(object $event): void
    {
        $handle = (string) $event->handle();
        $email = $event->email();

        if ($handle === '' || $email === null) {
            return;
        }

        $steps = FunnelStep::query()
            ->where('type', 'external')
            ->where('disabled', false)
            ->whereHas('funnel', fn ($query) => $query->where('published', true))
            ->with('funnel.steps', 'funnel.edges')
            ->get()
            ->filter(fn (FunnelStep $step) => (string) $step->config('automation') === $handle);

        foreach ($steps as $step) {
            $visit = $this->visitOfAddress($step->funnel, $email);

            if ($visit === null) {
                continue;
            }

            // Reached by the hand-over of the step before, or, for a walk that
            // never got that far, by this.
            $this->walk->enter($visit, $step);

            if (! $visit->recordOnce($step->node_key, FunnelStepEvent::AUTOMATION_FINISHED, ['automation' => $handle])) {
                continue;
            }

            $next = $this->walk->advance($visit, $step, 'default', FunnelStepEvent::AUTOMATION_FINISHED, [], recorded: true);

            if ($next !== null) {
                $this->walk->reach($visit, $next);
            }
        }
    }

    /**
     * Asked by the funnel's own lead magnet step: that one writes its own rows.
     */
    protected function ownRequest(object $grant): bool
    {
        return str_starts_with((string) (($grant->meta ?? [])['source'] ?? ''), 'funnel:');
    }

    /**
     * The lead magnet steps this resource answers for: published funnels, the
     * resource picked, and every way in an `external` step. A lead magnet step
     * that follows a form is the form's business and is left alone.
     *
     * @return Collection<int, FunnelStep>
     */
    protected function leadMagnetSteps(string $resource): Collection
    {
        if ($resource === '') {
            return collect();
        }

        return FunnelStep::query()
            ->where('type', 'lead_magnet')
            ->where('disabled', false)
            ->whereHas('funnel', fn ($query) => $query->where('published', true))
            ->with('funnel.steps', 'funnel.edges')
            ->get()
            ->filter(fn (FunnelStep $step) => (string) $step->config('resource') === $resource && $step->funnel->isOutsideOnly($step));
    }

    /**
     * The walk of the browser that sent this request, by its cookie.
     * Falls back to the walk that carries the address.
     */
    protected function visitOfRequest(Funnel $funnel, string $email): ?FunnelVisit
    {
        $token = app()->bound('request') ? app('request')->cookie(FunnelWalk::COOKIE) : null;

        if (is_string($token) && preg_match('/^[A-Za-z0-9]{32}$/', $token) === 1) {
            $visit = FunnelVisit::query()->where('funnel_id', $funnel->id)->where('token', $token)->first();

            if ($visit !== null) {
                return $visit;
            }
        }

        return $this->visitOfAddress($funnel, $email);
    }

    /**
     * The walk that carries this address: the one that asked for this grant,
     * else the latest one with the address.
     */
    protected function visitOfAddress(Funnel $funnel, string $email, ?string $nodeKey = null, ?int $grant = null): ?FunnelVisit
    {
        if ($email === '') {
            return null;
        }

        $visits = FunnelVisit::query()
            ->where('funnel_id', $funnel->id)
            ->whereRaw('lower(email) = ?', [$email])
            ->orderByDesc('id')
            ->get();

        if ($nodeKey !== null && $grant !== null) {
            $asked = $visits->first(fn (FunnelVisit $visit) => (int) ($visit->meta['lead_magnet'][$nodeKey]['grant_id'] ?? 0) === $grant);

            if ($asked !== null) {
                return $asked;
            }
        }

        return $visits->first();
    }

    protected function email(object $grant): string
    {
        return mb_strtolower(trim((string) ($grant->email ?? '')));
    }
}
