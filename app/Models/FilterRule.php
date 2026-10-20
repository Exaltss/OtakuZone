<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Models/FilterRule.php
 * Fungsi : aturan filter katalog/global/page/genre
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §3.1, §8.2
 */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FilterRule extends Model
{
    protected $table = 'filter_rules';

    protected $fillable = [
        'name',
        'scope',
        'condition_json',
        'is_active',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'condition_json' => 'array',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }
}
