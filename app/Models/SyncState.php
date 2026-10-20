<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Models/SyncState.php
 * Fungsi : checkpoint sync append-only
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §3.1, §5.4
 */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncState extends Model
{
    protected $table = 'sync_state';

    public $timestamps = false;

    protected $fillable = [
        'job_key',
        'last_page_ok',
        'cursor',
        'last_status',
        'last_run_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'last_page_ok' => 'integer',
            'last_run_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
