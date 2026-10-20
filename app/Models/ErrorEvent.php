<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Models/ErrorEvent.php
 * Fungsi : agregasi error by fingerprint
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §3.1, §11.5
 */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErrorEvent extends Model
{
    protected $table = 'error_events';

    public $timestamps = false;

    protected $fillable = [
        'level',
        'message',
        'context_json',
        'fingerprint',
        'count',
        'first_at',
        'last_at',
    ];

    protected function casts(): array
    {
        return [
            'context_json' => 'array',
            'count' => 'integer',
            'first_at' => 'datetime',
            'last_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $now = now();
            $model->first_at = $model->first_at ?? $now;
            $model->last_at = $model->last_at ?? $now;
        });
    }
}
