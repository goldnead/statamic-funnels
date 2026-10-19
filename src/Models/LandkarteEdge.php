<?php

namespace Goldnead\StatamicFunnels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A connection a person drew by hand, for a relation no addon can read from its
 * own data. Derived connections are never stored here.
 *
 * @property int $id
 * @property int $landkarte_id
 * @property string $from_type
 * @property string $from_ref
 * @property string $to_type
 * @property string $to_ref
 * @property string|null $label
 * @property Carbon|null $created_at
 */
class LandkarteEdge extends Model
{
    protected $table = 'funnel_landkarte_edges';

    protected $guarded = [];

    /** @return BelongsTo<Landkarte, $this> */
    public function landkarte(): BelongsTo
    {
        return $this->belongsTo(Landkarte::class, 'landkarte_id');
    }
}
