<?php

namespace Goldnead\StatamicFunnels\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Registries\StepRegistry;

/**
 * One step of a funnel as a target on a map.
 *
 * The id is `<funnel handle>:<node key>`. The node key is what the graph
 * itself refers to, so it survives a rename of the step's label.
 */
class FunnelStepMapObjects implements ProvidesMapObjects
{
    public function type(): string
    {
        return 'funnel_step';
    }

    public function typeLabel(): string
    {
        return __('statamic-funnels::messages.map_type_funnel_step');
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return FunnelStep::query()
            ->with('funnel')
            ->when($query !== null && $query !== '', function ($q) use ($query) {
                $like = '%'.addcslashes($query, '%_\\').'%';
                $q->where(fn ($w) => $w->where('label', 'like', $like)->orWhere('node_key', 'like', $like));
            })
            ->orderBy('funnel_id')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (FunnelStep $step) => $this->object($step))
            ->all();
    }

    public function find(string $id): ?MapObject
    {
        $step = $this->step($id);

        return $step ? $this->object($step) : null;
    }

    /** Visitors who stood on the step in the period, counted per visit, as `StepStats` does. */
    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        $step = $this->step($id);

        // A mail step is not a place anybody stands in; it has no visits.
        if (! $step || $step->type === 'mail') {
            return null;
        }

        $count = FunnelStepEvent::query()
            ->where('node_key', $step->node_key)
            ->where('event', FunnelStepEvent::ENTERED)
            ->whereBetween('created_at', [$period->from, $period->to])
            ->whereIn('visit_id', $step->funnel->visits()->select('id'))
            ->distinct()
            ->count('visit_id');

        return new MapMetric($count, __('statamic-funnels::messages.map_metric_visits'), $period);
    }

    protected function step(string $id): ?FunnelStep
    {
        [$handle, $nodeKey] = array_pad(explode(':', $id, 2), 2, '');

        $funnel = Funnel::query()->where('handle', $handle)->first();

        $step = $funnel?->steps()->where('node_key', $nodeKey)->first();

        return $step?->setRelation('funnel', $funnel);
    }

    protected function object(FunnelStep $step): MapObject
    {
        $funnel = $step->funnel;

        return new MapObject(
            type: $this->type(),
            id: $funnel->handle.':'.$step->node_key,
            label: $step->label ?: $step->node_key,
            cpUrl: cp_route('utilities.funnels.edit', $funnel->id),
            enabled: ! $step->disabled,
            detail: $funnel->title.' · '.self::kind($step->type),
        );
    }

    /** The step type's own name ("Lead magnet", "Page"), as the editor shows it. */
    public static function kind(string $type): string
    {
        $class = app(StepRegistry::class)->find($type);

        return $class ? $class::label() : $type;
    }
}
