<?php

namespace Goldnead\StatamicFunnels\Jobs;

use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Thumbnails\StepRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Photograph one step, later.
 *
 * Queued, because a browser takes a second or two per page and a save with six
 * pages must not take twelve seconds to come back. Unique per step, because
 * the editor saves often and the queue would otherwise hold five renders of the
 * same page, of which only the last one matters.
 *
 * The id travels, not the model: a step deleted between dispatch and run is a
 * job that finds nothing and does nothing, not one that fails deserialising.
 *
 * One try. A page that will not render will not render on the third attempt
 * either, and the warning is already in the log.
 */
class RenderStepThumbnail implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /** How long the uniqueness lock holds if a worker dies mid-render. */
    public int $uniqueFor = 300;

    public function __construct(public int $stepId, public bool $force = false) {}

    public function uniqueId(): string
    {
        return (string) $this->stepId;
    }

    public function handle(StepRenderer $renderer): void
    {
        $step = FunnelStep::query()->find($this->stepId);

        if (! $step) {
            return;
        }

        $renderer->render($step, $this->force);
    }
}
