<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Models/Rating.php
 * Fungsi : rating dan review user per anime
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §3.1
 */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rating extends Model
{
    protected $table = 'ratings';

    protected $fillable = [
        'user_id',
        'anime_id',
        'score',
        'review_text',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'anime_id' => 'integer',
            'score' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class);
    }
}
