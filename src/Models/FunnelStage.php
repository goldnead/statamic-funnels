<?php

namespace Goldnead\StatamicFunnels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One phase on a funnel's map: a column.
 *
 * @property int $id
 * @property int $funnel_id
 * @property string $name
 * @property int $position
 * @property Carbon|null $created_at
 * @property Collection<int, FunnelStageRef> $refs
 */
class FunnelStage extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Funnel, $this> */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    /** @return HasMany<FunnelStageRef, $this> */
    public function refs(): HasMany
    {
        return $this->hasMany(FunnelStageRef::class)->orderBy('position')->orderBy('id');
    }
}
