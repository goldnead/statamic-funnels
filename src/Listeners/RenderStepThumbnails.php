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
 *
 * On the `sync` queue the jobs run after the response has gone out, not inside
 * the save: a browser takes a second or two per page, and the editor should
 * have its "saved" back before the first shutter clicks.
 */
class RenderStepThumbnails
{
    public function handle(FunnelSaved $event): void
    {
        if (! Thumbnails::enabled() || ! Thumbnails::available()) {
            return;
        }

        $afterResponse = config('queue.default') === 'sync';

        foreach ($event->funnel->steps as $step) {
            if (! Thumbnails::isPage($step) || Thumbnails::isCurrent($step)) {
                continue;
            }

            $afterResponse
                ? RenderStepThumbnail::dispatchAfterResponse((int) $step->getKey())
                : RenderStepThumbnail::dispatch((int) $step->getKey());
        }
    }
}
