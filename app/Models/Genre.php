<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Models/Genre.php
 * Fungsi : master genre Animeku
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §3.1
 */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Genre extends Model
{
    protected $table = 'genres';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'slug',
        'external_key',
    ];
}
