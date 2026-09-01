<?php

namespace Goldnead\StatamicFunnels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $handle
 * @property string $title
 * @property string|null $description
 * @property bool $published
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Collection<int, FunnelStep> $steps
 * @property Collection<int, FunnelEdge> $edges
 */
class Funnel extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['published' => 'boolean', 'meta' => 'array'];
    }

    /** @return HasMany<FunnelStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(FunnelStep::class);
    }

    /** @return HasMany<FunnelEdge, $this> */
    public function edges(): HasMany
    {
        return $this->hasMany(FunnelEdge::class);
    }

    /** @return HasMany<FunnelVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(FunnelVisit::class);
    }

    /**
     * Where a walk begins.
     *
     * The one step with no incoming edge and an entry type. A funnel with none
     * cannot be walked at all, which is why the editor refuses to publish one.
     */
    public function entryStep(): ?FunnelStep
    {
        return $this->steps->firstWhere('type', 'entry');
    }

    public function stepByKey(string $nodeKey): ?FunnelStep
    {
        return $this->steps->firstWhere('node_key', $nodeKey);
    }

    public function stepBySlug(string $slug): ?FunnelStep
    {
        return $this->steps->firstWhere('slug', $slug);
    }

    /**
     * The step an edge leads to when leaving `$from` by `$output`.
     *
     * Null means the walk ends here. That is a legitimate shape — a funnel
     * whose "declined" branch simply stops — and it must not be confused with a
     * broken graph.
     */
    public function nextStep(string $fromNodeKey, string $output = 'default'): ?FunnelStep
    {
        // Eine Mail, die an demselben Ausgang haengt, ist kein Weiterweg. Sie
        // wird ausgeloest, nicht betreten — der Weg fuehrt an ihr vorbei zum
        // ersten Ziel, auf dem ein Mensch stehen kann.
        foreach ($this->edges->where('from_node_key', $fromNodeKey)->where('from_output', $output) as $edge) {
            $step = $this->stepByKey($edge->to_node_key);

            if ($step && $step->type !== 'mail') {
                return $step;
            }
        }

        return null;
    }

    /**
     * Die Mail-Knoten, die an einem Ausgang haengen.
     *
     * @return Collection<int, FunnelStep>
     */
    public function mailStepsFrom(string $fromNodeKey, string $output = 'default'): Collection
    {
        return $this->edges
            ->where('from_node_key', $fromNodeKey)
            ->where('from_output', $output)
            ->map(fn (FunnelEdge $edge) => $this->stepByKey($edge->to_node_key))
            ->filter(fn (?FunnelStep $step) => $step !== null && $step->type === 'mail' && ! $step->disabled)
            ->values();
    }
}
