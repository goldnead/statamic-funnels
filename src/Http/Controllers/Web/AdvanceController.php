<?php

namespace Goldnead\StatamicFunnels\Http\Controllers\Web;

use Goldnead\StatamicFunnels\Events\FunnelFormSubmitted;
use Goldnead\StatamicFunnels\Events\FunnelOfferAccepted;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\Countdown;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\Basket;
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

        // You can only leave a step you are standing on. Every page is directly
        // reachable by URL — it has to be, because the provider and half the
        // emails link straight into the middle of a flow — but *advancing* from
        // one nobody entered is how somebody skips a form, or takes the
        // accepted branch of an offer they never saw.
        abort_unless($visit->hasReached($step->node_key), 403);

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

        // The deadline, enforced. A countdown that only counts is a lie told in
        // Javascript: the number runs out, the visitor reloads, and the offer is
        // still there. Checked before anything else on the accepting path, so a
        // late order cannot start a payment.
        if (Countdown::expired($step, $visit)) {
            return back()->withErrors(['offer' => __('statamic-funnels::messages.offer_expired')]);
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

        // What was actually ticked and typed, checked against the offer rather
        // than believed. The browser says which boxes were checked; the offer
        // says which boxes exist, and only their intersection is bought.
        $basket = Basket::make(
            $offer,
            array_values(array_filter((array) $request->input('bumps', []), 'is_string')),
            config('statamic-funnels.coupons', true) ? (string) $request->input('coupon', '') : null,
        );

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

            $this->rememberPending($visit, $step, $payment);

            // A recurring charge is usually accepted now and settled later, so
            // the payment comes back `pending` more often than not. Moving on
            // here would be the one thing this whole family is written against:
            // treating acceptance as payment. The webhook decides, exactly as
            // on the checkout path — `AdvanceOnPayment` picks it up.
            if (! $payment->isPaid()) {
                return $this->waiting($funnel, $step);
            }

            $next = $this->walk->advance($visit, $step, 'accepted', FunnelStepEvent::ACCEPTED, [
                'payment_id' => $payment->getKey(),
            ]);

            FunnelOfferAccepted::dispatch($visit->fresh() ?? $visit, $step, $payment);

            return $this->go($funnel, $next);
        }

        // Back into the funnel, not to the site's thank-you page. A buyer who
        // returns from the provider outside the flow they were walking has been
        // dropped halfway through a purchase — and the rest of the funnel, the
        // part that was meant to follow the sale, never happens.
        $accepted = $funnel->nextStep($step->node_key, 'accepted');

        // Not twice. A second submit — a double click, a reloaded confirmation,
        // an impatient visitor — would start a second payment and overwrite the
        // first one's id on the visit. The webhook of the first would then find
        // nothing: the money arrives and the walk stands still.
        if ($existing = $this->pendingPaymentFor($visit, $step)) {
            return $existing->isPaid()
                ? $this->go($funnel, $funnel->nextStep($step->node_key, 'accepted'))
                : $this->waiting($funnel, $step);
        }

        $result = $this->checkout->start($basket->handles(), [
            'email' => $visit->email,
            'name' => $visit->name,
        ], $accepted?->slug
            ? route('statamic-funnels.step', [$funnel->handle, $accepted->slug])
            : route('statamic-funnels.entry', $funnel->handle), $basket->discount());

        if (! $result) {
            return back()->withErrors(['offer' => __('statamic-funnels::messages.offer_unavailable')]);
        }

        $this->rememberPending($visit, $step, $result->payment);

        // Off to the provider. Nothing is accepted yet — only the webhook
        // decides that, exactly as everywhere else in this family.
        return redirect()->away($result->checkoutUrl);
    }

    /**
     * Note which payment this step started.
     *
     * Kept per step as well as on the visit: a walk can pay more than once —
     * the thing they came for, then an upsell — and a single `payment_id` would
     * point at the newest one while the older one's webhook was still in
     * flight.
     */
    protected function rememberPending(FunnelVisit $visit, FunnelStep $step, Payment $payment): void
    {
        $meta = $visit->meta ?? [];
        $meta['pending_step'] = $step->node_key;
        $meta['payments'][$step->node_key] = $payment->getKey();

        $visit->forceFill([
            'payment_id' => $payment->getKey(),
            'meta' => $meta,
        ])->save();
    }

    protected function pendingPaymentFor(FunnelVisit $visit, FunnelStep $step): ?Payment
    {
        $id = data_get($visit->meta, 'payments.'.$step->node_key);

        if (! $id) {
            return null;
        }

        $payment = Payment::find($id);

        // A refused charge is not a reason to stop somebody buying: they got
        // nothing, so they may try again.
        return $payment && $payment->status !== Payment::STATUS_FAILED ? $payment : null;
    }

    /** Sent back to the offer with a note that the money is on its way. */
    protected function waiting(Funnel $funnel, FunnelStep $step)
    {
        return back()->with('statamic-funnels.waiting', $step->node_key);
    }

    protected function go(Funnel $funnel, ?FunnelStep $next)
    {
        if (! $next) {
            return redirect()->route('statamic-funnels.entry', $funnel->handle);
        }

        return redirect()->route('statamic-funnels.step', [$funnel->handle, $next->slug]);
    }
}
