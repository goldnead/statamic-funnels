<?php

namespace Goldnead\StatamicFunnels\Listeners;

use Goldnead\StatamicFunnels\Events\FunnelSaved;
use Goldnead\StatamicFunnels\Jobs\RenderStepThumbnail;
use Goldnead\StatamicFunnels\Thumbnails\Thumbnails;

/**
 * After a save, queue a picture for every page whose picture is out of date.
 *
 * Nothing is queued when no renderer is bound: a queue full of jobs that would
 * each open, find nothing to render with and close again is noise, and the
 * editor's side panel already says why there are no pictures. A step whose
 * fingerprint still matches its stored picture is skipped too, so saving a
 * renamed edge does not photograph six pages.
 */
class RenderStepThumbnails
{
    public function handle(FunnelSaved $event): void
    {
        if (! Thumbnails::enabled() || ! Thumbnails::available()) {
            return;
        }

        foreach ($event->funnel->steps as $step) {
            if (! Thumbnails::isPage($step) || Thumbnails::isCurrent($step)) {
                continue;
            }

            RenderStepThumbnail::dispatch((int) $step->getKey());
        }
    }
}
