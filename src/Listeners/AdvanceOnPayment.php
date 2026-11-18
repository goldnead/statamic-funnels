<?php

namespace Goldnead\StatamicFunnels\Listeners;

use Goldnead\StatamicFunnels\Events\FunnelOfferAccepted;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The money arrived, so the walk moves on.
 *
 * Hung off the payment addon's own paid event, never off the return from the
 * provider: a buyer who closes the tab still paid, and a buyer who lands on the
 * return page has not necessarily. Only the webhook decides, and this is how a
 * funnel hears about it.
 *
 * Autoloaded by core off the first parameter type below.
 */
class AdvanceOnPayment
{
    public function handle(PaymentPaid $event): void
    {
        $payment = $event->payment;
        $visit = $this->visitFor($payment);

        if (! $visit) {
            // A payment that did not come out of a funnel. The ordinary case on
            // a site that also sells things directly.
            return;
        }

        $nodeKey = $this->stepKeyFor($visit, $payment);
        $step = $nodeKey !== null ? $visit->funnel?->stepByKey($nodeKey) : null;

        if (! $step) {
            // Paid money that belongs to a funnel walk, and no step to credit it
            // to: the step was deleted while the money was in flight, or the
            // visit lost track of it. Nobody gets the purchase mail, no tag, no
            // conversion. That has to be visible.
            Log::error('statamic-funnels: a paid payment belongs to a funnel visit, but no step could be found for it; the walk did not advance.', [
                'payment_id' => $payment->getKey(),
                'visit_id' => $visit->getKey(),
                'funnel_id' => $visit->funnel_id,
                'node_key' => $nodeKey,
            ]);

            return;
        }

        // Record and advance in one transaction. Written separately, a save
        // that threw after the ACCEPTED row was in would leave a purchase that
        // looks handled, and the guard below would refuse every redelivery.
        $advanced = DB::transaction(function () use ($visit, $step, $payment): bool {
            // Once. A provider redelivers by design, and a funnel that advanced
            // per delivery would march somebody through three steps for one
            // purchase.
            if ($visit->events()->where('node_key', $step->node_key)->where('event', FunnelStepEvent::ACCEPTED)->exists()) {
                return false;
            }

            // The read above is the fast path; the unique `once_key` decides.
            // Two deliveries in the same moment both read "not yet", and only
            // one of them may write and advance.
            if (! $visit->recordOnce($step->node_key, FunnelStepEvent::ACCEPTED, [
                'payment_id' => $payment->getKey(),
            ])) {
                return false;
            }

            // Move the walk on as well as noting it. The buyer is on their way
            // back from the provider; if the visit still points at the offer,
            // the "carry on where you left off" link sends them to a page asking
            // them to buy what they have just bought.
            $next = $visit->funnel->nextStep($step->node_key, 'accepted');

            if ($next) {
                $visit->forceFill(['current_node_key' => $next->node_key])->save();
            }

            return true;
        });

        if (! $advanced) {
            return;
        }

        // After the commit, so a listener never sees a purchase that is rolled
        // back a moment later. The listeners (mail, LeadHub, webhooks) guard
        // themselves; if one throws anyway, the purchase stays recorded and
        // the failure goes to the log instead of vanishing behind the guard.
        try {
            FunnelOfferAccepted::dispatch($visit->fresh() ?? $visit, $step, $payment);
        } catch (Throwable $e) {
            Log::error('statamic-funnels: the purchase was recorded, but a listener of FunnelOfferAccepted failed.', [
                'payment_id' => $payment->getKey(),
                'visit_id' => $visit->getKey(),
                'node_key' => $step->node_key,
                'exception' => $e,
            ]);
        }
    }

    /**
     * The visit this payment came out of.
     *
     * The payment names it itself (`meta.funnel_visit_id`, set at checkout and
     * on the one-click path). Only payments from before that fall back to the
     * visit's `payment_id`, which points at the newest payment of the walk and
     * no longer at an older one whose webhook is still in flight.
     */
    protected function visitFor(Payment $payment): ?FunnelVisit
    {
        $id = data_get($payment->meta, 'funnel_visit_id');

        $visit = is_int($id) || (is_string($id) && ctype_digit($id))
            ? FunnelVisit::query()->find((int) $id)
            : null;

        return $visit ?? FunnelVisit::query()->where('payment_id', $payment->getKey())->first();
    }

    /**
     * The step that started exactly this payment.
     *
     * `meta.payments` maps step to payment for every purchase of the walk;
     * `pending_step` is only the newest one and is trusted only when the visit
     * still points at this very payment.
     */
    protected function stepKeyFor(FunnelVisit $visit, Payment $payment): ?string
    {
        $meta = (array) ($visit->meta ?? []);

        foreach ((array) ($meta['payments'] ?? []) as $nodeKey => $paymentId) {
            if ((string) $paymentId === (string) $payment->getKey()) {
                return (string) $nodeKey;
            }
        }

        $pending = $meta['pending_step'] ?? null;

        if (is_string($pending) && (string) $visit->payment_id === (string) $payment->getKey()) {
            return $pending;
        }

        return null;
    }
}
