<?php

namespace Goldnead\StatamicFunnels\Models;

use Goldnead\StatamicFunnels\Thumbnails\Thumbnails;
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

    /**
     * A step that goes takes its picture with it. On the model event, so every
     * path that deletes a step model — the graph writer, a command, a tinker
     * session — cleans up the same way. A funnel deleted whole cascades at the
     * database and never comes through here; its folder is removed by the
     * controller instead.
     */
    protected static function booted(): void
    {
        static::deleted(fn (FunnelStep $step) => Thumbnails::forget($step));
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
