<?php

namespace Goldnead\StatamicFunnels\Events;

use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A visitor arrived somewhere, for the first time.
 *
 * The seam an automation hangs off: "send the reminder when somebody reaches
 * the offer and does not buy" is an automation, not a funnel feature, and this
 * is how it hears about it.
 */
class FunnelStepEntered
{
    use Dispatchable;

    public function __construct(
        public readonly FunnelVisit $visit,
        public readonly FunnelStep $step,
    ) {}
}
