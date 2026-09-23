<?php

namespace Goldnead\StatamicFunnels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $visit_id
 * @property string $node_key
 * @property string $event
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 */
class FunnelStepEvent extends Model
{
    public const ENTERED = 'entered';

    public const SUBMITTED = 'submitted';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const COMPLETED = 'completed';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /** @return BelongsTo<FunnelVisit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(FunnelVisit::class, 'visit_id');
    }
}
