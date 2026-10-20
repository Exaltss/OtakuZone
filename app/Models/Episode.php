<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Models/Episode.php
 * Fungsi : episode anime, quality_urls encrypted at-rest
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §3.1, §3.3, §4.3
 */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Episode extends Model
{
    protected $table = 'episodes';

    protected $fillable = [
        'anime_id',
        'external_id',
        'number',
        'title',
        'thumbnail_path',
        'duration_sec',
        'quality_urls',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'anime_id' => 'integer',
            'number' => 'decimal:2',
            'duration_sec' => 'integer',
            'quality_urls' => 'encrypted:array',
            'is_hidden' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class);
    }

    public function watchHistory(): HasMany
    {
        return $this->hasMany(WatchHistory::class);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }
}
