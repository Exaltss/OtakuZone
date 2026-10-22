<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Anime;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class OgController extends Controller
{
    public function show(string $slug): Response
    {
        $anime = Anime::query()->visible()->where('slug', $slug)->firstOrFail();

        $title = e($anime->title . ' · ' . config('app.name', 'Zonaotaku'));
        $desc = e(Str::limit((string) ($anime->synopsis ?: 'Tonton streaming anime ' . $anime->title . ' di Zonaotaku.'), 180));
        $targetUrl = e(url('/anime/' . $anime->slug));
        $imageUrl = $anime->image_path
            ? e(url('/storage/' . ltrim((string) $anime->image_path, '/')))
            : e(url('/favicon.ico'));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{$title}</title>
    <meta property="og:type" content="video.tv_show">
    <meta property="og:title" content="{$title}">
    <meta property="og:description" content="{$desc}">
    <meta property="og:url" content="{$targetUrl}">
    <meta property="og:image" content="{$imageUrl}">
    <meta name="twitter:card" content="summary_large_image">
    <meta http-equiv="refresh" content="0;url={$targetUrl}">
</head>
<body style="background:#020617;color:#e2e8f0;font-family:sans-serif;padding:2rem;">
    <p>Mengalihkan ke <a href="{$targetUrl}" style="color:#38bdf8;">{$title}</a>...</p>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
