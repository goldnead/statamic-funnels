<?php

namespace Goldnead\StatamicFunnels\Events;

use Goldnead\StatamicFunnels\Models\Funnel;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The whole graph has been written, and the transaction is over.
 *
 * Fired after the commit, not inside it, so a listener that queues work sees
 * the steps the job will later find — a job dispatched from inside the
 * transaction on a sync queue would read the table before the write landed.
 * The funnel carries its steps and edges, freshly loaded.
 */
class FunnelSaved
{
    use Dispatchable;

    public function __construct(public readonly Funnel $funnel) {}
}
