<?php

namespace Goldnead\StatamicFunnels\Http\Controllers\Web;

use Goldnead\StatamicFunnels\Events\FunnelFormSubmitted;
use Goldnead\StatamicFunnels\Events\FunnelOfferAccepted;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\FollowUp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Moving on from a step.
 *
 * A normal form post with a CSRF token, deliberately: this is a browser, a
 * person, and — on an offer step — an order. A page on another site must not be
 * able to advance somebody's funnel, let alone buy something in it.
 */
class AdvanceController
{
    public function __construct(
        protected FunnelWalk $walk,
        protected Checkout $checkout,
        protected FollowUp $followUp,
    ) {}

    public function __invoke(Request $request, string $funnel, string $nodeKey)
    {
        $model = Funnel::with(['steps', 'edges'])->where('handle', $funnel)->first();

        abort_unless($model && $model->published, 404);

        $step = $model->stepByKey($nodeKey);

        abort_unless($step && ! $step->disabled, 404);

        $visit = $this->walk->visit($model);

        return match ($step->type) {
            'offer' => $this->offer($request, $model, $step, $visit),
            'capture' => $this->capture($request, $model, $step, $visit),
            default => $this->plain($model, $step, $visit),
        };
    }

    /** An ordinary "continue". */
    protected function plain(Funnel $funnel, FunnelStep $step, $visit)
    {
        $next = $this->walk->advance($visit, $step, 'default', FunnelStepEvent::SUBMITTED);

        return $this->go($funnel, $next);
    }

    /**
     * A form step.
     *
     * The submission itself belongs to Statamic — its form, its validation, its
     * notifications, its submissions screen. What happens here is only that the
     * walk learns who this is and moves on.
     */
    protected function capture(Request $request, Funnel $funnel, FunnelStep $step, $visit)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'name' => ['nullable', 'string', 'max:191'],
        ]);

        $visit->forceFill([
            'email' => $data['email'],
            'name' => $data['name'] ?? $visit->name,
        ])->save();

        // Announced before moving on, so a sibling that wants the contact gets
        // it whether or not the next step exists.
        FunnelFormSubmitted::dispatch($visit->fresh() ?? $visit, $step, $data);

        $next = $this->walk->advance($visit, $step, 'default', FunnelStepEvent::SUBMITTED, [
            'email' => $data['email'],
        ]);

        return $this->go($funnel, $next);
    }

    /**
     * An offer step: accepted or declined.
     *
     * Declining is a first-class answer, not an error. Most visitors decline,
     * and a funnel that treats that as a failure has nowhere to send them.
     */
    protected function offer(Request $request, Funnel $funnel, FunnelStep $step, $visit)
    {
        if (! $request->boolean('accept')) {
            $next = $this->walk->advance($visit, $step, 'declined', FunnelStepEvent::DECLINED);

            return $this->go($funnel, $next);
        }

        $request->validate([
            // The order button's own confirmation. Not decoration: it is the
            // record that somebody clicked something labelled as an order.
            'confirmed' => ['accepted'],
        ]);

        $offer = Offer::query()->where('handle', (string) $step->config('offer'))->first();

        if (! $offer || ! $offer->isSellable()) {
            Log::warning('statamic-funnels: an offer step points at something that cannot be sold.', [
                'funnel' => $funnel->handle,
                'step' => $step->node_key,
                'offer' => $step->config('offer'),
            ]);

            return back()->withErrors(['offer' => __('statamic-funnels::messages.offer_unavailable')]);
        }

        $prefix = (string) config('statamic-offers.handle_prefix', 'offer:');
        $buyHandle = $prefix.$offer->handle;

        // Already paid once in this walk? Then this is a follow-up, charged
        // against what the first payment left behind, and the buyer types
        // nothing. Otherwise it is a first checkout and they go to the provider.
        $previous = $visit->payment_id
            ? Payment::find($visit->payment_id)
            : null;

        if ($previous && $this->followUp->eligible($previous)) {
            $payment = $this->followUp->accept($previous, $buyHandle, [
                'funnel' => $funnel->handle,
                'step' => $step->node_key,
            ]);

            if (! $payment) {
                return back()->withErrors(['offer' => __('statamic-funnels::messages.offer_unavailable')]);
            }

            $visit->record($step->node_key, FunnelStepEvent::ACCEPTED, ['payment_id' => $payment->getKey()]);
            FunnelOfferAccepted::dispatch($visit, $step, $payment);

            $next = $this->walk->advance($visit, $step, 'accepted', FunnelStepEvent::ACCEPTED, [
                'payment_id' => $payment->getKey(),
            ]);

            return $this->go($funnel, $next);
        }

        // Back into the funnel, not to the site's thank-you page. A buyer who
        // returns from the provider outside the flow they were walking has been
        // dropped halfway through a purchase — and the rest of the funnel, the
        // part that was meant to follow the sale, never happens.
        $accepted = $funnel->nextStep($step->node_key, 'accepted');

        $result = $this->checkout->start($buyHandle, [
            'email' => $visit->email,
            'name' => $visit->name,
        ], $accepted?->slug
            ? route('statamic-funnels.step', [$funnel->handle, $accepted->slug])
            : route('statamic-funnels.entry', $funnel->handle));

        if (! $result) {
            return back()->withErrors(['offer' => __('statamic-funnels::messages.offer_unavailable')]);
        }

        // The walk remembers which payment it started, so the webhook can find
        // its way back here when the money actually arrives.
        $visit->forceFill([
            'payment_id' => $result->payment->getKey(),
            'meta' => array_merge($visit->meta ?? [], ['pending_step' => $step->node_key]),
        ])->save();

        // Off to the provider. Nothing is accepted yet — only the webhook
        // decides that, exactly as everywhere else in this family.
        return redirect()->away($result->checkoutUrl);
    }

    protected function go(Funnel $funnel, ?FunnelStep $next)
    {
        if (! $next) {
            return redirect()->route('statamic-funnels.entry', $funnel->handle);
        }

        return redirect()->route('statamic-funnels.step', [$funnel->handle, $next->slug]);
    }
}
