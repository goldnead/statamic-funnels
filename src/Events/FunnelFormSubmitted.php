<?php

namespace Goldnead\StatamicFunnels\Events;

use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A native Statamic form was submitted from inside a funnel.
 *
 * The submission itself is Statamic's, with its own storage, notifications and
 * screen. This event only says which walk it belonged to.
 *
 * @property array<string, mixed> $values
 */
class FunnelFormSubmitted
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public readonly FunnelVisit $visit,
        public readonly FunnelStep $step,
        public readonly array $values,
    ) {}
}
