<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/smoke_ops.php
 * Fungsi : smoke offline command operasional tanpa jaringan asli
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5, §9, §11, §13.5
 */
declare(strict_types=1);

use App\Models\Anime;
use App\Models\Episode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

DB::beginTransaction();

$fail = function (string $message): never {
    DB::rollBack();
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

config([
    'zonaotaku.animeku.throttle_ms' => 0,
    'zonaotaku.animeku.jitter_ms' => 0,
    'zonaotaku.sync.throttle_ms' => 0,
    'zonaotaku.sync.jitter_ms' => 0,
]);

$rand = bin2hex(random_bytes(6));
$external = 'smoke-detail-' . $rand;

$anime = Anime::create([
    'external_id' => $external,
    'cat_id' => 7,
    'title' => 'Smoke Detail Before',
    'slug' => 'smoke-detail-before-' . $rand,
    'source' => 'animeku',
    'image_path' => 'thumbnails/referenced-' . $rand . '.jpg',
    'synced_at' => now(),
]);

$episode = Episode::create([
    'anime_id' => $anime->id,
    'external_id' => 'smoke-detail-ep-' . $rand,
    'number' => 1,
    'title' => 'Smoke Detail Before Eps 01',
    'quality_urls' => [
        '720' => 'http://greip.whatbox.ca:29318/old.mp4',
    ],
    'synced_at' => now(),
]);

$thumbDir = storage_path('app/public/thumbnails');
@mkdir($thumbDir, 0775, true);

$referencedFile = $thumbDir . '/' . basename((string) $anime->image_path);
$orphanFile = $thumbDir . '/orphan-' . $rand . '.jpg';

file_put_contents($referencedFile, 'referenced');
file_put_contents($orphanFile, 'orphan');
touch($orphanFile, time() - (86400 * 2));

Http::fake(function ($request) use ($external) {
    $url = (string) $request->url();

    if (str_contains($url, 'get_anime_detail') || str_contains($url, 'get_post_detail')) {
        return Http::response([
            'status' => 'ok',
            'latest_anime' => [
                [
                    'vid' => $external,
                    'cat_id' => 7,
                    'video_title' => 'Smoke Detail Updated Eps 01',
                    'video_id' => 'smoke-detail-ep-' . $external,
                    'video_thumbnail' => 'updated.jpg',
                    'video_duration' => '24 Menit',
                    'video_description' => '<p>Sinopsis detail. Link https://secret.example/path</p>',
                    'video_url' => 'http://santol.whatbox.ca:29318/smoke-360.mp4',
                    'video_url_minihd' => 'http://honeydew.whatbox.ca:29318/smoke-480.mp4',
                    'video_url_hd' => 'http://greip.whatbox.ca:29318/new.mp4',
                    'video_url_fullhd' => 'http://rambutan.whatbox.ca:29318/smoke-1080.mp4',
                ],
            ],
        ]);
    }

    return Http::response(['status' => 'ok', 'latest_anime' => []]);
});

$call = static function (string $command, array $params, array $fragments, string $label) use ($fail): void {
    try {
        $code = Artisan::call($command, $params);
    } catch (Throwable $e) {
        $fail("{$label} throwable: {$e->getMessage()}");
    }

    $output = Artisan::output();

    if ($code !== 0) {
        $fail("{$label} exit {$code}: {$output}");
    }

    foreach ($fragments as $fragment) {
        if (!str_contains($output, $fragment)) {
            $fail("{$label} missing fragment [{$fragment}]: {$output}");
        }
    }

    if (preg_match('/\bError\b|\bFatal error\b|Call to a member function|Uncaught/i', $output)) {
        $fail("{$label} contains runtime error: {$output}");
    }
};

$call('sync:detail', ['--anime' => $anime->id], ['sync:detail ok anime_id='], 'sync:detail');

$episode->refresh();
$anime->refresh();

if ($episode->title !== 'Smoke Detail Updated Eps 01') {
    $fail('sync:detail tidak update judul episode');
}

$quality = $episode->quality_urls;

if (!is_array($quality) || ($quality['720'] ?? null) !== 'http://greip.whatbox.ca:29318/new.mp4') {
    $fail('sync:detail tidak update quality 720');
}

$rawJson = json_encode($anime->raw_meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($rawJson === false) {
    $fail('raw_meta smoke detail gagal di-json');
}

if (str_contains($rawJson, 'http://') || str_contains($rawJson, 'https://')) {
    $fail('raw_meta smoke detail masih menyimpan URL mentah');
}

$call('prune', ['--dry-run' => true], ['prune ok audit='], 'prune:dry-run');

if (!is_file($orphanFile)) {
    $fail('prune dry-run menghapus file orphan');
}

if (!is_file($referencedFile)) {
    $fail('prune dry-run menghapus file referenced');
}

$call('backup:offsite', ['--dry-run' => true], ['DRY_RUN encrypted='], 'backup:dry-run');
$call('reencrypt:urls', ['--dry-run' => true], ['reencrypt:urls dry_run=true'], 'reencrypt:dry-run');
$call('zo:queue-tick', [], ['queue-tick'], 'queue-tick');

@unlink($orphanFile);
@unlink($referencedFile);

DB::rollBack();

echo "OK: ops pipeline offline smoke lulus.\n";
