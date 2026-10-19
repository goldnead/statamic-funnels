<?php

namespace Goldnead\StatamicFunnels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A named map of what a business is made of across days and addons: pages,
 * lead magnets, automations, courses, checkouts and products on phase lanes.
 *
 * @property int $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Collection<int, LandkarteStage> $stages
 * @property Collection<int, LandkarteEdge> $edges
 */
class Landkarte extends Model
{
    protected $table = 'funnel_landkarten';

    protected $guarded = [];

    /**
     * The database cascades on MySQL; SQLite does not enforce foreign keys
     * unless asked to, so the children go here as well.
     */
    protected static function booted(): void
    {
        static::deleting(function (Landkarte $landkarte) {
            $stageIds = $landkarte->stages()->pluck('id');
            LandkarteRef::query()->whereIn('stage_id', $stageIds)->delete();
            LandkarteStage::query()->whereIn('id', $stageIds)->delete();
            LandkarteEdge::query()->where('landkarte_id', $landkarte->id)->delete();
        });
    }

    /** @return HasMany<LandkarteStage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(LandkarteStage::class, 'landkarte_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<LandkarteEdge, $this> */
    public function edges(): HasMany
    {
        return $this->hasMany(LandkarteEdge::class, 'landkarte_id');
    }
}
