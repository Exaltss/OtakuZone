<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Models/GroupItem.php
 * Fungsi : anggota group dengan urutan manual
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §3.1, §8.2
 */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupItem extends Model
{
    protected $table = 'group_items';

    public $timestamps = false;

    protected $fillable = [
        'group_id',
        'anime_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'anime_id' => 'integer',
            'position' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->created_at = $model->created_at ?? now();
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class);
    }
}
