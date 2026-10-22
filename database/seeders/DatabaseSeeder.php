<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Genre;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@zonaotaku.my.id'],
            [
                'name' => 'Super Admin Zonaotaku',
                'password' => Hash::make('AdminZonaotaku2026!'),
                'role' => 'super_admin',
                'email_verified_at' => now(),
            ]
        );

        $genres = [
            'Action', 'Adventure', 'Comedy', 'Drama', 'Fantasy',
            'Horror', 'Isekai', 'Mecha', 'Mystery', 'Psychological',
            'Romance', 'Sci-Fi', 'Slice of Life', 'Sports', 'Supernatural', 'Thriller',
        ];

        foreach ($genres as $name) {
            Genre::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'external_key' => Str::slug($name),
                ]
            );
        }

        if (Setting::get('animeku.base_url_primary') === null) {
            Setting::set('animeku.base_url_primary', (string) config('zonaotaku.animeku.base_url_primary'));
        }
        if (Setting::get('animeku.stream_header_profile') === null) {
            Setting::set('animeku.stream_header_profile', 'default');
        }
    }
}
