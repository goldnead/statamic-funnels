<?php

namespace Goldnead\StatamicFunnels\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelVisit;

/**
 * A whole funnel as a target on a map: the "Kurs verkaufen" funnel that a
 * course phase hands over to.
 *
 * The id is the handle, the same string the public URL carries, so a
 * reference survives an export and an import into another install.
 */
class FunnelMapObjects implements ProvidesMapObjects
{
    public function type(): string
    {
        return 'funnel';
    }

    public function typeLabel(): string
    {
        return __('statamic-funnels::messages.map_type_funnel');
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return Funnel::query()
            ->when($query !== null && $query !== '', function ($q) use ($query) {
                $like = '%'.addcslashes($query, '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('handle', 'like', $like));
            })
            ->orderBy('title')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (Funnel $funnel) => $this->object($funnel))
            ->all();
    }

    public function find(string $id): ?MapObject
    {
        $funnel = Funnel::query()->where('handle', $id)->first();

        return $funnel ? $this->object($funnel) : null;
    }

    /** Walks begun in the period, one per visitor, the rule `Visits` counts by. */
    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        $funnel = Funnel::query()->where('handle', $id)->first();

        if (! $funnel) {
            return null;
        }

        $count = FunnelVisit::query()
            ->where('funnel_id', $funnel->id)
            ->whereBetween('created_at', [$period->from, $period->to])
            ->count();

        return new MapMetric($count, __('statamic-funnels::messages.map_metric_visits'), $period);
    }

    protected function object(Funnel $funnel): MapObject
    {
        return new MapObject(
            type: $this->type(),
            id: $funnel->handle,
            label: $funnel->title,
            cpUrl: cp_route('utilities.funnels.edit', $funnel->id),
            enabled: (bool) $funnel->published,
            detail: parse_url(route('statamic-funnels.entry', $funnel->handle), PHP_URL_PATH) ?: null,
        );
    }
}
