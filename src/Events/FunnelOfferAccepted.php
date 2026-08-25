<?php

namespace Goldnead\StatamicFunnels\Events;

use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An offer inside a funnel was paid for.
 *
 * Dispatched off the payment's own paid event, not off the click: the payment
 * addon decides what "paid" means, and this addon must not invent a second
 * answer.
 */
class FunnelOfferAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly FunnelVisit $visit,
        public readonly FunnelStep $step,
        public readonly Payment $payment,
    ) {}
}
