<?php

namespace Goldnead\StatamicFunnels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One phase lane of a Landkarte: a name and the line saying what the phase is for.
 *
 * @property int $id
 * @property int $landkarte_id
 * @property string $name
 * @property string|null $purpose
 * @property int $position
 * @property Carbon|null $created_at
 * @property Collection<int, LandkarteRef> $refs
 */
class LandkarteStage extends Model
{
    protected $table = 'funnel_landkarte_stages';

    protected $guarded = [];

    /** @return BelongsTo<Landkarte, $this> */
    public function landkarte(): BelongsTo
    {
        return $this->belongsTo(Landkarte::class, 'landkarte_id');
    }

    /** @return HasMany<LandkarteRef, $this> */
    public function refs(): HasMany
    {
        return $this->hasMany(LandkarteRef::class, 'stage_id')->orderBy('position')->orderBy('id');
    }
}
