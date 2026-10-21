<?php

namespace Goldnead\StatamicFunnels\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapPeriod;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Stache\Query\EntryQueryBuilder;

/**
 * A Statamic entry (a page) as a target on a map.
 *
 * Statamic itself is the owner here, and funnels already points at entries
 * from its page steps. There is no number: page views are not stored by
 * Statamic, and a figure from some other tool would be a figure nobody here
 * could check.
 */
class EntryMapObjects implements ProvidesMapObjects
{
    use SimulatesEntry;

    public function type(): string
    {
        return 'entry';
    }

    public function typeLabel(): string
    {
        return __('statamic-funnels::messages.map_type_entry');
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        /** @var EntryQueryBuilder $builder */
        $builder = Entry::query();

        // The site the Control Panel is on: in a multi-site install each brand
        // has its own, and a picker that listed every site's pages would offer
        // another brand's.
        $builder->where('site', Site::selected()->handle());

        if ($query !== null && $query !== '') {
            $builder->where('title', 'like', '%'.$query.'%');
        }

        return $builder
            ->orderBy('title')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($entry) => $this->object($entry))
            ->all();
    }

    public function find(string $id): ?MapObject
    {
        $entry = Entry::find($id);

        return $entry instanceof StatamicEntry ? $this->object($entry) : null;
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }

    /**
     * A live page is public, so anyone on the map may see it described; a draft only with the
     * right to see the entries of its collection.
     */
    public function detailsAuthorized(string $id, $user): bool
    {
        $entry = Entry::find($id);

        if (! $entry instanceof StatamicEntry) {
            return false;
        }

        return $entry->published()
            || ($user !== null && $user->can('view '.$entry->collectionHandle().' entries'));
    }

    /**
     * The page in a few words: title, address, whether it is live. No picture:
     * Statamic keeps none for a page, and rendering one per opened panel would
     * mean a headless browser for a glance.
     *
     * @return list<array<string, mixed>>|null
     */
    public function details(string $id, MapPeriod $period): ?array
    {
        $entry = Entry::find($id);

        if (! $entry instanceof StatamicEntry) {
            return null;
        }

        $url = $entry->url();

        return [['kind' => 'facts', 'title' => null, 'rows' => [
            ['label' => __('statamic-funnels::messages.map_details_title'), 'value' => (string) ($entry->get('title') ?: $entry->slug())],
            ['label' => __('statamic-funnels::messages.map_details_url'), 'value' => (string) $url, 'href' => $entry->published() ? $url : null],
            ['label' => __('statamic-funnels::messages.map_details_status'), 'value' => $entry->published()
                ? __('statamic-funnels::messages.map_details_published')
                : __('statamic-funnels::messages.map_details_draft')],
        ]]];
    }

    protected function object(StatamicEntry $entry): MapObject
    {
        return new MapObject(
            type: $this->type(),
            id: (string) $entry->id(),
            label: (string) ($entry->get('title') ?: $entry->slug() ?: $entry->id()),
            cpUrl: $entry->editUrl(),
            enabled: (bool) $entry->published(),
            detail: trim(($entry->url() ?? '').' · '.($entry->collection()?->title() ?? $entry->collectionHandle()), ' ·'),
        );
    }
}
