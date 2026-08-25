<?php

namespace Goldnead\StatamicFunnels\Listeners;

use Goldnead\StatamicFunnels\Events\FunnelOfferAccepted;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicPayments\Events\PaymentPaid;

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
        $visit = FunnelVisit::query()
            ->where('payment_id', $event->payment->getKey())
            ->first();

        if (! $visit) {
            // A payment that did not come out of a funnel. The ordinary case on
            // a site that also sells things directly.
            return;
        }

        $nodeKey = data_get($visit->meta, 'pending_step');
        $step = is_string($nodeKey) ? $visit->funnel->stepByKey($nodeKey) : null;

        if (! $step) {
            return;
        }

        // Once. A provider redelivers by design, and a funnel that advanced per
        // delivery would march somebody through three steps for one purchase.
        if ($visit->events()->where('node_key', $step->node_key)->where('event', FunnelStepEvent::ACCEPTED)->exists()) {
            return;
        }

        $visit->record($step->node_key, FunnelStepEvent::ACCEPTED, [
            'payment_id' => $event->payment->getKey(),
        ]);

        // Move the walk on as well as noting it. The buyer is on their way back
        // from the provider; if the visit still points at the offer, the "carry
        // on where you left off" link sends them to a page asking them to buy
        // what they have just bought.
        $next = $visit->funnel->nextStep($step->node_key, 'accepted');

        if ($next) {
            $visit->forceFill(['current_node_key' => $next->node_key])->save();
        }

        FunnelOfferAccepted::dispatch($visit->fresh() ?? $visit, $step, $event->payment);
    }
}
