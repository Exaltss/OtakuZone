<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Models/Group.php
 * Fungsi : kelompok anime manual/rule-based
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §3.1, §8.2
 */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $table = 'groups';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'rule_json',
    ];

    protected function casts(): array
    {
        return [
            'rule_json' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(GroupItem::class);
    }
}
