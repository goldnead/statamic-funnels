<?php

namespace Goldnead\StatamicFunnels\Tags;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Statamic\Tags\Tags;

/**
 * Funnels, for a template outside a funnel.
 *
 * {{ funnels:link handle="fruehlingskurs" }} — where a funnel starts.
 * {{ funnels:progress handle="fruehlingskurs" }} … {{ /funnels:progress }}
 *
 * The second one is what a normal page uses to say "you were partway through
 * this" — a link back into a walk somebody abandoned, which is worth more than
 * most of what a funnel does at the front.
 */
class Funnels extends Tags
{
    protected static $handle = 'funnels';

    public function link(): string
    {
        $funnel = $this->funnel();

        return $funnel ? route('statamic-funnels.entry', $funnel->handle) : '';
    }

    public function progress(): array|string
    {
        $funnel = $this->funnel();

        if (! $funnel) {
            return $this->parseNoResults();
        }

        $visit = app(FunnelWalk::class)->visit($funnel);
        $step = $visit->current_node_key ? $funnel->stepByKey($visit->current_node_key) : null;

        if (! $step || $visit->completed_at) {
            return $this->parseNoResults();
        }

        return $this->parse([
            'funnel' => $funnel->handle,
            'title' => $funnel->title,
            'step' => $step->label ?: $step->type,
            'url' => $step->slug
                ? route('statamic-funnels.step', [$funnel->handle, $step->slug])
                : route('statamic-funnels.entry', $funnel->handle),
        ]);
    }

    protected function funnel(): ?Funnel
    {
        $handle = (string) $this->params->get('handle', '');

        if ($handle === '') {
            return null;
        }

        $funnel = Funnel::with(['steps', 'edges'])->where('handle', $handle)->first();

        // An unpublished funnel is not linkable. A "continue where you left off"
        // link into something half-built is how a visitor meets a broken page.
        return $funnel && $funnel->published ? $funnel : null;
    }
}
