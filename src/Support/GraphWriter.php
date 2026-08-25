<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
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

            foreach ($data['nodes'] ?? [] as $node) {
                // A type nobody registered is dropped rather than stored. A
                // stored node the runtime cannot render is a page that 500s in
                // the middle of somebody's purchase.
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
                        'config' => $node['config'] ?? [],
                        'disabled' => (bool) ($node['disabled'] ?? false),
                    ],
                );
            }

            // Gone from the canvas, gone from the table.
            $funnel->steps()->whereNotIn('node_key', $keys ?: ['__none__'])->delete();

            $funnel->edges()->delete();

            foreach ($data['edges'] ?? [] as $edge) {
                // An edge to or from a node that is not there any more would be
                // a path leading off the map.
                if (! in_array($edge['from_node_key'], $keys, true) || ! in_array($edge['to_node_key'], $keys, true)) {
                    continue;
                }

                $funnel->edges()->create([
                    'from_node_key' => $edge['from_node_key'],
                    'to_node_key' => $edge['to_node_key'],
                    'from_output' => $edge['from_output'] ?? 'default',
                ]);
            }
        });
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
