<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Events\FunnelSaved;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Thumbnails\Thumbnails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saving what somebody drew.
 *
 * The whole graph arrives at once, because that is what an editor with undo has
 * to send: a diff of a canvas somebody has been dragging around for ten minutes
 * is a diff nobody can reason about. So the write is a replace, inside one
 * transaction, and either all of it lands or none of it does.
 */
class GraphWriter
{
    public function __construct(protected StepRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function write(Funnel $funnel, array $data): void
    {
        DB::transaction(function () use ($funnel, $data) {
            $funnel->forceFill([
                'title' => $data['title'],
                'handle' => $data['handle'],
                'published' => (bool) ($data['published'] ?? false),
            ])->save();

            $keys = [];

            // Steps this site cannot draw right now: their addon was removed
            // or downgraded. They are not the editor's to delete. It has no
            // form for them and cannot have changed them, and dropping them
            // on a save would take the step and its paths with it, silently,
            // for a site that only unplugged an addon for an afternoon.
            $unavailable = $funnel->steps()->get()
                ->reject(fn ($step) => $this->registry->has($step->type))
                ->keyBy('node_key');

            foreach ($data['nodes'] ?? [] as $node) {
                // A stored node of an unavailable type stays exactly as it is,
                // whatever the payload says about it.
                if ($unavailable->has($node['node_key'])) {
                    $keys[] = $node['node_key'];

                    continue;
                }

                // A type nobody registered is dropped rather than stored. A
                // stored node the runtime cannot render is a page that 500s in
                // the middle of somebody's purchase. (Only a *new* one: see above.)
                if (! $this->registry->has($node['type'])) {
                    continue;
                }

                $keys[] = $node['node_key'];

                $funnel->steps()->updateOrCreate(
                    ['node_key' => $node['node_key']],
                    [
                        'type' => $node['type'],
                        'label' => $node['label'] ?? null,
                        'slug' => $this->slug($funnel, $node),
                        // The thumbnail key is the server's, not the editor's:
                        // the picture is taken after the save, and a second
                        // save before the page was reloaded would otherwise
                        // send the old config back and throw it away.
                        'config' => Thumbnails::keepStored(
                            $node['config'] ?? [],
                            $funnel->steps->firstWhere('node_key', $node['node_key']),
                        ),
                        'disabled' => (bool) ($node['disabled'] ?? false),
                    ],
                );
            }

            // Gone from the canvas, gone from the table — one model at a time,
            // so the step's own `deleted` hook removes its picture with it.
            // Except what the canvas could not show.
            $keys = array_values(array_unique(array_merge($keys, $unavailable->keys()->all())));

            $funnel->steps()->whereNotIn('node_key', $keys ?: ['__none__'])->get()->each->delete();

            // Paths of an unavailable step the payload did not carry stay.
            // When the payload does carry the step, the canvas drew it and the
            // payload's paths are the truth.
            $drawn = collect($data['nodes'] ?? [])->pluck('node_key')->all();
            $kept = $funnel->edges()->get()->filter(
                fn ($edge) => (($unavailable->has($edge->from_node_key) && ! in_array($edge->from_node_key, $drawn, true))
                    || ($unavailable->has($edge->to_node_key) && ! in_array($edge->to_node_key, $drawn, true)))
                    && in_array($edge->from_node_key, $keys, true)
                    && in_array($edge->to_node_key, $keys, true),
            )->map(fn ($edge) => $edge->only(['from_node_key', 'to_node_key', 'from_output']));

            $funnel->edges()->delete();

            foreach ($kept as $edge) {
                $funnel->edges()->create($edge);
            }

            foreach ($data['edges'] ?? [] as $edge) {
                // An edge to or from a node that is not there any more would be
                // a path leading off the map.
                if (! in_array($edge['from_node_key'], $keys, true) || ! in_array($edge['to_node_key'], $keys, true)) {
                    continue;
                }

                $funnel->edges()->firstOrCreate([
                    'from_node_key' => $edge['from_node_key'],
                    'to_node_key' => $edge['to_node_key'],
                    'from_output' => $edge['from_output'] ?? 'default',
                ]);
            }
        });

        // After the commit, with the rows as they now are: a listener that
        // queues work must find what the job will later look for.
        FunnelSaved::dispatch($funnel->load(['steps', 'edges']));
    }

    /** A brand-new funnel gets the one step every funnel must have. */
    public function seed(Funnel $funnel): void
    {
        $funnel->steps()->create([
            'node_key' => 'entry_'.Str::random(8),
            'type' => 'entry',
            'label' => __('statamic-funnels::nodes.entry_label'),
            'slug' => null,
            'config' => [],
        ]);
    }

    /**
     * The slug a visitor sees.
     *
     * Derived from the label, kept stable once set, and null for the entry step
     * — that one lives under the funnel's own URL, because a visitor should not
     * have to know they are in a funnel to be in one.
     *
     * @param  array<string, mixed>  $node
     */
    protected function slug(Funnel $funnel, array $node): ?string
    {
        $class = $this->registry->find($node['type']);

        if (! $class || ! $class::isPage() || $node['type'] === 'entry') {
            return null;
        }

        $existing = $funnel->steps->firstWhere('node_key', $node['node_key']);

        if ($existing?->slug) {
            // Left alone once it exists: a slug that changes when somebody
            // renames a step breaks every link already sent out.
            return $existing->slug;
        }

        $base = Str::slug((string) ($node['label'] ?? $node['type'])) ?: $node['type'];
        $slug = $base;
        $i = 2;

        while ($funnel->steps()->where('slug', $slug)->where('node_key', '!=', $node['node_key'])->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
