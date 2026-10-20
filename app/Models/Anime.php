<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Models/Anime.php
 * Fungsi : entitas anime utama, append-only, merge tombstone
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §3.1, §5.6, §8.1
 */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Anime extends Model
{
    protected $table = 'anime';

    protected $fillable = [
        'external_id',
        'cat_id',
        'title',
        'slug',
        'image_path',
        'synopsis',
        'type',
        'status',
        'year',
        'season',
        'episodes_total',
        'rating_avg',
        'view_count',
        'popularity_score',
        'source',
        'raw_meta',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'cat_id' => 'integer',
            'year' => 'integer',
            'episodes_total' => 'integer',
            'rating_avg' => 'decimal:2',
            'view_count' => 'integer',
            'popularity_score' => 'integer',
            'is_hidden' => 'boolean',
            'raw_meta' => 'array',
            'synced_at' => 'datetime',
            'merged_into_id' => 'integer',
        ];
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    public function watchlists(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function groupItems(): HasMany
    {
        return $this->hasMany(GroupItem::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function mergedFrom(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false)->whereNull('merged_into_id');
    }
}
