<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/FetchPosters.php
 * Fungsi : unduh poster dari AniList GraphQL ke storage lokal; multi-varian query +
 *          pencocokan synonyms + skor kemiripan; gagal = lewati aman (placeholder)
 * Rujuk  : perbaikan aset poster, 2026-10-20 (v2)
 */
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Anime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class FetchPosters extends Command
{
    protected $signature = 'zo:fetch-posters
                            {--limit=60 : Maksimum anime per perjalanan}
                            {--all : Proses semua anime meski sudah punya poster}
                            {--retry-failed : Reset penanda gagal sebelum menjalankan}
                            {--dry-run : Hanya tampilkan rencana, jangan unduh/tulis}';

    protected $description = 'Unduh poster dari AniList ke storage lokal. Bila AniList error/judul tak cocok, poster tetap placeholder (aman).';

    private const API = 'https://graphql.anilist.co';

    private const GQL = 'query ($search: String) { Page(perPage: 10) { media(search: $search%s) { id title { romaji english native } synonyms startDate { year } coverImage { large } } } }';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $all = (bool) $this->option('all');
        $dry = (bool) $this->option('dry-run');

        if ((bool) $this->option('retry-failed')) {
            $reset = Anime::query()->whereNotNull('poster_skipped_at')->update(['poster_skipped_at' => null]);
            $this->line("➜ penanda gagal direset: {$reset} baris");
        }

        $query = Anime::query()->visible()->orderBy('id');

        if (!$all) {
            $query->where(static fn ($q) => $q->whereNull('image_path')->orWhere('image_path', ''))
                ->whereNull('poster_skipped_at');
        }

        $ok = 0;
        $fail = 0;

        foreach ($query->limit($limit)->get() as $anime) {
            $coverUrl = $this->searchCover((string) $anime->title, $anime->year, (string) ($anime->synopsis ?? ''));

            if ($coverUrl === null) {
                $fail++;
                $this->line('  ✗ ' . $anime->title . ' (tidak cocok di AniList — dilewati, tidak diulang scheduler)');

                if (!$dry) {
                    $anime->poster_skipped_at = now();
                    $anime->save();
                }

                continue;
            }

            if (!$dry) {
                $saved = $this->savePoster((int) $anime->id, $coverUrl);

                if ($saved === null) {
                    $fail++;
                    $this->line('  ✗ ' . $anime->title . ' (unduhan tidak valid — dilewati)');

                    continue;
                }

                $anime->image_path = $saved;
                $anime->poster_skipped_at = null;
                $anime->save();
            }

            $ok++;
            $this->line('  ✓ ' . $anime->title);
        }

        $this->info("fetch-posters selesai: ok={$ok} gagal={$fail} (gagal = aman, poster tetap placeholder)");

        return self::SUCCESS;
    }

    /**
     * Cari cover terbaik: coba beberapa varian judul, cocokkan via synonyms + kemiripan.
     */
    private function searchCover(string $rawTitle, ?int $year, string $synopsis = ''): ?string
    {
        $override = $this->overrideCover($rawTitle);

        if ($override !== null) {
            return $override;
        }

        foreach ($this->queryVariants($rawTitle, $synopsis) as $variant) {
            $media = $this->anilistSearch($variant);

            if ($media === null || $media === []) {
                continue;
            }

            $cover = $this->bestMatch($media, $rawTitle, $year);

            if ($cover !== null) {
                return $cover;
            }
        }

        $last = $this->anilistSearch(trim(rtrim($rawTitle, '.!?;:,')), false);

        if ($last !== null && $last !== []) {
            return $this->bestMatch($last, $rawTitle, $year);
        }

        return null;
    }

    /**
     * Varian query (maks 6 panggilan/anime, early-exit saat cocok):
     * 1 judul bersih · 2 judul utama sebelum ':' · 3 inti tanpa suffix season ·
     * 4 empat kata terakhir · 5 kata terpanjang · 6 inti-tanpa-spasi + suffix season.
     *
     * @return array<int, string>
     */
    private function queryVariants(string $raw): array
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', $raw));
        $clean = rtrim($clean, '.!?;:,');

        $suffix = '/\s+(?:\d+(?:st|nd|rd|th)?\s*Season|Season\s*\d+|Part\s*\d+|OVAs?|Specials?|Movie|II|III|IV|V|2nd|3rd|Final|Cour|Dub)\s*$/i';

        $suffixRaw = '';

        if (preg_match($suffix, $clean, $sm) === 1) {
            $suffixRaw = trim($sm[0]);
        }

        $core = trim(rtrim(trim((string) preg_replace($suffix, '', $clean)), ':;-'));

        $main = $clean;

        if (str_contains($clean, ':')) {
            $main = trim(rtrim(explode(':', $clean, 2)[0], ':;- '));
        }

        $variants[] = $clean;

        $core2 = trim((string) preg_replace('/\s+\d{1,2}$/', '', $clean));

        if ($core2 !== '' && $core2 !== $clean && !in_array($core2, $variants, true)) {
            $variants[] = $core2;
        }

        if ($main !== '' && $main !== $clean && strlen($main) >= 4) {
            $variants[] = $main;
        }

        if ($core !== '' && $core !== $clean && !in_array($core, $variants, true)) {
            $variants[] = $core;
        }

        $words = preg_split('/\s+/', $clean) ?: [];

        if (count($words) > 4) {
            $variants[] = implode(' ', array_slice($words, -4));
        }

        if (count($words) > 3) {
            $variants[] = implode(' ', array_slice($words, -3));
        }

        if (count($words) > 6) {
            $variants[] = implode(' ', array_slice($words, 0, 4));
            $variants[] = implode(' ', array_slice($words, 0, 6));
        }

        foreach ([$core2, $clean] as $pairSource) {
            $pw = preg_split('/\s+/', $pairSource) ?: [];

            if (count($pw) >= 4 && count($pw) <= 8 && count($pw) % 2 === 0) {
                $pairs = [];

                for ($i = 0; $i < count($pw); $i += 2) {
                    $pairs[] = $pw[$i] . ($pw[$i + 1] ?? '');
                }

                $gluedPairs = implode(' ', $pairs);

                if (!in_array($gluedPairs, $variants, true)) {
                    $variants[] = $gluedPairs;
                }
            }
        }

        $noParen = trim((string) preg_replace('/\s*\(\d{4}\)/', ' ', $core));

        if ($noParen !== '' && $noParen !== $core && !in_array($noParen, $variants, true)) {
            $variants[] = $noParen;
        }

        $longest = '';

        foreach ($words as $w) {
            $bare = (string) preg_replace('/[^A-Za-z0-9]/', '', $w);

            if (strlen($bare) >= 7 && strlen($bare) > strlen($longest)) {
                $longest = $bare;
            }
        }

        if ($longest !== '' && !in_array($longest, $variants, true)) {
            $variants[] = $longest;
        }

        if ($core !== '' && $suffixRaw !== '') {
            $glued = (string) preg_replace('/\s+/', '', $core) . ' ' . $suffixRaw;

            if (!in_array($glued, $variants, true)) {
                $variants[] = $glued;
            }
        }

        return array_slice(array_values(array_unique($variants)), 0, 9);
    }

    /**
     * Skor kandidat: cocok persis > prefix/containment > subset token > levenshtein.
     * Penalti bila penanda season tidak konsisten (hindari poster musim salah).
     *
     * @param array<int, array<string, mixed>> $media
     */
    private function bestMatch(array $media, string $rawTitle, ?int $year): ?string
    {
        $needle = $this->norm($rawTitle);
        $needleTokens = $this->tokens($rawTitle);
        $needleSeason = $this->seasonMark($rawTitle);
        $best = null;
        $bestScore = 0.0;
        $bestRaw = 0.0;

        foreach ($media as $m) {
            $names = [
                (string) ($m['title']['romaji'] ?? ''),
                (string) ($m['title']['english'] ?? ''),
                (string) ($m['title']['native'] ?? ''),
            ];

            foreach ((array) ($m['synonyms'] ?? []) as $syn) {
                $names[] = (string) $syn;
            }

            foreach ($names as $name) {
                $n = $this->norm($name);

                if ($n === '') {
                    continue;
                }

                if ($n === $needle) {
                    $score = 1.0;
                } elseif (strlen($n) >= 6 && (str_starts_with($needle, $n) || str_starts_with($n, $needle))) {
                    $score = 0.93;
                } else {
                    $candTokens = $this->tokens($name);
                    $overlap = count(array_intersect($candTokens, $needleTokens));
                    $coverage = $candTokens === [] ? 0.0 : $overlap / count($candTokens);

                    if ($coverage >= 0.8 && $overlap >= 2) {
                        $score = 0.90;
                    } elseif ($coverage >= 0.75 && $overlap >= 3 && count($candTokens) >= 3) {
                        $score = 0.88;
                    } else {
                        $score = $this->similarity($n, $needle);
                    }
                }

                $raw = $score;

                if ($this->seasonMark($name) !== $needleSeason) {
                    $score -= 0.15;
                }

                if ($score > $bestScore || ($score === $bestScore && $raw > $bestRaw)) {
                    $bestScore = $score;
                    $bestRaw = $raw;
                    $best = $m;
                }
            }
        }

        if ($best === null) {
            return null;
        }

        $accept = $bestScore >= 0.80
            || ($bestScore >= 0.7 && $year !== null && (int) ($best['startDate']['year'] ?? 0) === $year)
            || $bestRaw >= 0.90;

        if (!$accept) {
            return null;
        }

        $cover = $best['coverImage']['large'] ?? null;

        return is_string($cover) && $cover !== '' ? $cover : null;
    }

    private function norm(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/i', '', mb_strtolower($value));
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $value): array
    {
        $parts = preg_split('/[^a-z0-9]+/i', mb_strtolower($value)) ?: [];

        return array_values(array_filter($parts, static fn (string $p): bool => strlen($p) >= 2));
    }

    private function seasonMark(string $value): string
    {
        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s*Season\b/i', $value, $m) === 1) {
            return (string) (int) $m[1];
        }

        if (preg_match('/\bSeason\s*(\d{1,2})\b/i', $value, $m) === 1) {
            return (string) (int) $m[1];
        }

        if (preg_match('/\b(II|III|IV|V|VI|2nd|3rd|2|3)\b/', $value, $m) === 1) {
            return match (strtoupper($m[1])) { 'II', '2ND', '2' => '2', 'III', '3RD', '3' => '3', 'IV' => '4', 'V' => '5', 'VI' => '6', default => '' };
        }

        return '';
    }

    private function similarity(string $a, string $b): float
    {
        if (strlen($a) > 255) {
            $a = substr($a, 0, 255);
        }

        if (strlen($b) > 255) {
            $b = substr($b, 0, 255);
        }

        $len = max(strlen($a), strlen($b));

        if ($len === 0) {
            return 0.0;
        }

        return 1.0 - (levenshtein($a, $b) / $len);
    }

    /**
     * Peta manual judul -> AniList ID untuk kasus penamaan yang tidak bisa
     * dicocokkan otomatis. File: storage/app/poster-overrides.json
     */
    private function overrideCover(string $rawTitle): ?string
    {
        static $map = null;

        if ($map === null) {
            $map = [];
            $path = function_exists('storage_path')
                ? storage_path('app/poster-overrides.json')
                : (getenv('ZO_POSTER_OVERRIDES') ?: null);

            if (is_string($path) && $path !== '' && is_file($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);

                if (is_array($decoded)) {
                    $map = $decoded;
                }
            }
        }

        $id = $map[trim($rawTitle)] ?? null;

        if (!is_int($id) && !is_numeric((string) $id)) {
            return null;
        }

        return $this->anilistCoverById((int) $id);
    }

    private function anilistCoverById(int $id): ?string
    {
        try {
            $res = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
                    'Accept' => 'application/json',
                ])
                ->post(self::API, [
                    'query' => 'query ($id: Int) { Media(id: $id, type: ANIME) { coverImage { large } } }',
                    'variables' => ['id' => $id],
                ]);

            usleep(700000 + random_int(0, 300000));

            if (!$res->successful()) {
                return null;
            }

            $cover = $res->json('data.Media.coverImage.large');

            return is_string($cover) && $cover !== '' ? $cover : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function anilistSearch(string $search, bool $withType = true): ?array
    {
        try {
            $res = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
                    'Accept' => 'application/json',
                ])
                ->post(self::API, [
                    'query' => sprintf(self::GQL, $withType ? ', type: ANIME' : ''),
                    'variables' => ['search' => $search],
                ]);

            // hormati rate limit AniList (~90 req/menit)
            usleep(700000 + random_int(0, 300000));

            if (!$res->successful()) {
                return null;
            }

            $media = $res->json('data.Page.media');

            return is_array($media) ? $media : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function savePoster(int $animeId, string $url): ?string
    {
        try {
            $res = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Zonaotaku/1.0 (poster mirror; local cache)'])
                ->get($url);

            if (!$res->successful()) {
                return null;
            }

            $body = $res->body();

            if (strlen($body) < 1024 || strlen($body) > 3145728) {
                return null;
            }

            $head = substr($body, 0, 12);
            $valid = str_starts_with($head, "\xFF\xD8\xFF")
                || str_starts_with($head, "\x89PNG")
                || str_starts_with($head, 'RIFF');

            if (!$valid) {
                return null;
            }

            $ctype = (string) $res->header('Content-Type');
            $ext = match (true) {
                str_contains($ctype, 'png') => 'png',
                str_contains($ctype, 'webp') => 'webp',
                default => 'jpg',
            };

            $path = 'posters/' . $animeId . '.' . $ext;

            Storage::disk('public')->put($path, $body);

            return $path;
        } catch (Throwable) {
            return null;
        }
    }
}
