<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Models/WatchHistory.php
 * Fungsi : riwayat posisi nonton per episode
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §3.1, §9.1
 */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchHistory extends Model
{
    protected $table = 'watch_history';

    protected $fillable = [
        'user_id',
        'episode_id',
        'position_sec',
        'duration_sec',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'episode_id' => 'integer',
            'position_sec' => 'integer',
            'duration_sec' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }
}
