<?php

namespace Goldnead\StatamicFunnels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pointer from a lane to an object in some addon: a type and an id, nothing
 * more. Everything shown about the object is asked of its owner.
 *
 * @property int $id
 * @property int $stage_id
 * @property string $type
 * @property string $ref_id
 * @property int $position
 * @property Carbon|null $created_at
 */
class LandkarteRef extends Model
{
    protected $table = 'funnel_landkarte_refs';

    protected $guarded = [];

    /** @return BelongsTo<LandkarteStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(LandkarteStage::class, 'stage_id');
    }
}
