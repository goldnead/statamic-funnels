<?php

namespace Goldnead\StatamicFunnels\Events;

use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Illuminate\Foundation\Events\Dispatchable;

/** Somebody walked all the way through. */
class FunnelCompleted
{
    use Dispatchable;

    public function __construct(public readonly FunnelVisit $visit) {}
}
