<?php

namespace Goldnead\StatamicFunnels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One place on the path.
 *
 * @property int $id
 * @property int $funnel_id
 * @property string $node_key
 * @property string $type
 * @property string|null $label
 * @property string|null $slug
 * @property array<string, mixed>|null $config
 * @property bool $disabled
 * @property Carbon|null $created_at
 */
class FunnelStep extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['config' => 'array', 'disabled' => 'boolean'];
    }

    /** @return BelongsTo<Funnel, $this> */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config ?? [], $key, $default);
    }
}
