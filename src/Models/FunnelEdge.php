<?php

namespace Goldnead\StatamicFunnels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $funnel_id
 * @property string $from_node_key
 * @property string $to_node_key
 * @property string $from_output
 */
class FunnelEdge extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Funnel, $this> */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }
}
