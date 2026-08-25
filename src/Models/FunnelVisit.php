<?php

namespace Goldnead\StatamicFunnels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person's walk through one funnel.
 *
 * @property int $id
 * @property int $funnel_id
 * @property string $token
 * @property string|null $current_node_key
 * @property string|null $email
 * @property string|null $name
 * @property int|null $payment_id
 * @property Carbon|null $completed_at
 * @property array<string, mixed>|null $meta
 */
class FunnelVisit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'meta' => 'array'];
    }

    /** @return BelongsTo<Funnel, $this> */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    /** @return HasMany<FunnelStepEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(FunnelStepEvent::class, 'visit_id');
    }

    public function record(string $nodeKey, string $event, array $payload = []): FunnelStepEvent
    {
        return $this->events()->create([
            'node_key' => $nodeKey,
            'event' => $event,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }

    /** Whether this visitor has already been through a step. */
    public function hasReached(string $nodeKey): bool
    {
        return $this->events()->where('node_key', $nodeKey)->exists();
    }
}
