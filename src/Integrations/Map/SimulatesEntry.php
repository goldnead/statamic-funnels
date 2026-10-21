<?php

namespace Goldnead\StatamicFunnels\Integrations\Map;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\StatamicFunnels\Support\EntryAddress;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;

/**
 * What a page would do in a dry run: a person who reaches it sees it. Part of {@see EntryMapObjects}: the map
 * registry finds these methods with `method_exists`.
 *
 * The entry is read and described (title, address). It is not rendered and not requested: the dry run does not
 * ask the site for a page, because whatever counts visits there would count this one.
 *
 * @phpstan-require-extends EntryMapObjects
 */
trait SimulatesEntry
{
    /** A page begins nothing by itself: it is reached over a connection. */
    public function paths(string $id): array
    {
        return [];
    }

    /** As guarded as the panel details: a live page is public, a draft needs the right to see its collection. */
    public function simulationAuthorized(string $id, $user): bool
    {
        return $this->detailsAuthorized($id, $user);
    }

    /** @return list<Effect> */
    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        $entry = Entry::find($id);

        if (! $entry instanceof StatamicEntry) {
            return [Effect::unknown(__('statamic-funnels::messages.sim_entry_missing'))];
        }

        // A draft is no page for a visitor: the site answers 404.
        if (! $entry->published()) {
            return [Effect::stop(__('statamic-funnels::messages.sim_entry_draft'))];
        }

        // She sees the page, and what the map connects to it carries on: an event with no edge kind named goes
        // along every connection (a page that is a step of a funnel leads into that funnel).
        return [
            new Effect(
                Effect::PAGE,
                __('statamic-funnels::messages.sim_entry_page', ['title' => (string) ($entry->get('title') ?: $entry->slug())]),
                (string) EntryAddress::path($entry),
                0,
                ['url' => (string) EntryAddress::path($entry), 'entry' => (string) $entry->id()],
            ),
            Effect::emit(new SimEvent(SimEvent::ENTERED)),
        ];
    }
}
