<?php

namespace Goldnead\StatamicFunnels\Models;

use Goldnead\StatamicFunnels\Thumbnails\Thumbnails;
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
 * @property Carbon|null $updated_at
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

    /**
     * A funnel that goes takes its pictures with it. The steps cascade at the
     * database, which skips their own `deleted` hooks, so the folder is removed
     * here — before the row goes, while the id is still known.
     */
    protected static function booted(): void
    {
        static::deleting(function (Funnel $funnel) {
            Thumbnails::forgetFunnel($funnel);

            // The map goes with the funnel on every database. The foreign keys
            // cascade on MySQL; SQLite does not enforce them unless asked to.
            $stageIds = $funnel->stages()->pluck('id');
            FunnelStageRef::query()->whereIn('funnel_stage_id', $stageIds)->delete();
            FunnelStage::query()->whereIn('id', $stageIds)->delete();
        });
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

    /** @return HasMany<FunnelStage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(FunnelStage::class)->orderBy('position')->orderBy('id');
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

    /**
     * Whether a step is only ever reached from outside the funnel's own pages.
     *
     * Every way in is an `external` step: the visitor gets there because a page
     * of the site, lead-magnets or an automation says so, never by opening an
     * address under `/f`. Such a step is not a public page. Opened by hand it
     * would write a visit and an arrival for somebody who was never on the
     * journey, and the numbers of the map would count people who guessed a URL.
     */
    public function isOutsideOnly(FunnelStep $step): bool
    {
        $from = $this->edges->where('to_node_key', $step->node_key)->pluck('from_node_key');

        if ($from->isEmpty()) {
            return false;
        }

        return $from->every(fn (string $key) => $this->stepByKey($key)?->type === 'external');
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
