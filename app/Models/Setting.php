<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Models/Setting.php
 * Fungsi : setting runtime, nilai sensitif encrypted
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §2.1, §3.3, §8.2
 */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'settings';

    public $timestamps = false;

    protected $fillable = [
        'key_name',
        'value',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'is_public' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key_name', $key)->first();

        return $setting?->value ?? $default;
    }

    public static function set(string $key, mixed $value, bool $isPublic = false): static
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return static::query()->updateOrCreate(
            ['key_name' => $key],
            [
                'value' => $value,
                'is_public' => $isPublic,
                'updated_at' => now(),
            ]
        );
    }
}
