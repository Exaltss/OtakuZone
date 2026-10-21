<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Support/SynopsisParser.php
 * Fungsi : memisahkan blok metadata MAL dari sinopsis + ekstraksi daftar genre
 * Rujuk  : dipakai AnimeController (detail) dan zo:backfill-genres (index genre)
 */
declare(strict_types=1);

namespace App\Support;

final class SynopsisParser
{
    /**
     * Urutan kunci metadata MAL yang menempel di depan kolom synopsis.
     * Alternation disusun agar 'Information Type' tidak tertukar dengan kunci lain.
     */
    public const META_PATTERN = '/(Alternative Titles|Synonyms|Japanese|English|Information Type|Episodes|Status|Aired|Premiered|Broadcast|Producers|Licensors|Studios|Source|Genres|Themes|Theme|Demographic|Duration|Rating)\s*:/u';

    private const META_LABELS = [
        'Alternative Titles' => 'Judul alternatif',
        'Synonyms' => 'Sinonim',
        'Japanese' => 'Judul asli',
        'English' => 'Judul English',
        'Information Type' => 'Tipe',
        'Episodes' => 'Episode',
        'Status' => 'Status',
        'Aired' => 'Tayang',
        'Premiered' => 'Musim',
        'Broadcast' => 'Jadwal siaran',
        'Producers' => 'Produser',
        'Licensors' => 'Lisensi',
        'Studios' => 'Studio',
        'Source' => 'Adaptasi',
        'Genres' => 'Genre',
        'Themes' => 'Tema',
        'Theme' => 'Tema',
        'Demographic' => 'Demografis',
        'Duration' => 'Durasi',
        'Rating' => 'Rating konten',
    ];

    private const META_NOISE = ['unknown', 'none', 'none found, add some', 'not available', '?'];

    /**
     * Whitelist genre kanonikal MAL (genre + tema + demografis yang lazim muncul
     * pada baris "Genres:" upstream). Token di luar daftar ini dianggap noise.
     */
    private const GENRE_CANON = [
        'Action', 'Adventure', 'Avant Garde', 'Award Winning', 'Boys Love', 'Comedy',
        'Crime', 'Drama', 'Ecchi', 'Erotica', 'Fantasy', 'Girls Love', 'Gourmet',
        'Hentai', 'Historical', 'Horror', 'Kids', 'Mystery', 'Romance', 'Sci-Fi',
        'Slice of Life', 'Sports', 'Supernatural', 'Suspense', 'Seinen', 'Shoujo',
        'Shounen', 'Josei', 'Mecha', 'Music', 'Psychological', 'School', 'Military',
        'Police', 'Parody', 'Super Power', 'Martial Arts', 'Magic', 'Game', 'Thriller',
        'Tokusatsu', 'Vampire', 'Space', 'Samurai', 'Demons', 'Myths & Mythology',
        'Strategy Game', 'Combat Sports', 'Racing', 'Time Travel', 'Reincarnation',
        'Isekai', 'Harem', 'Reverse Harem', 'Love Polygon', 'Gore', 'Workplace',
        'Showbiz', 'Idols', 'Otaku Culture', 'Performing Arts', 'Visual Arts',
        'Video Game', 'Pets', 'Childcare', 'Detective', 'Delinquents', 'Crossdressing',
        'CGDCT', 'Anthology', 'Adult Cast', 'Educational', 'Gag Humor', 'Medical',
        'Organized Crime', 'Mahou Shoujo', 'Romantic Subtext',
    ];

    private static function normGenre(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/\s*-\s*/', '-', $value);
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return $value;
    }

    private static function canonGenre(string $token): ?string
    {
        static $map = null;

        if ($map === null) {
            $map = [];
            foreach (self::GENRE_CANON as $canon) {
                $map[self::normGenre($canon)] = $canon;
            }
        }

        return $map[self::normGenre($token)] ?? null;
    }

    /**
     * Pisahkan blok metadata MAL dari sinopsis asli tanpa menebak-nebak.
     * Sinopsis asli hanya diambil bila marker " Sinopsis "/" Synopsis " benar-benar ada
     * (marker bisa bahasa Indonesia maupun Inggris, pemisah kadang NBSP U+00A0);
     * selain itu dianggap tidak tersedia daripada menampilkan metadata sebagai sinopsis.
     *
     * @return array{synopsis: string|null, genres: array<int,string>, meta: array<int,array{label:string,value:string}>}
     */
    public static function parse(?string $raw): array
    {
        $text = trim((string) $raw);

        if ($text === '') {
            return ['synopsis' => null, 'genres' => [], 'meta' => []];
        }

        $hasMeta = preg_match(self::META_PATTERN, $text) === 1;

        if (!$hasMeta) {
            return ['synopsis' => $text, 'genres' => [], 'meta' => []];
        }

        $markerStart = false;
        $markerEnd = false;

        if (preg_match('/[\s\x{00A0}]+S[iny]nopsis[\s\x{00A0}]+/u', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $markerStart = (int) $m[0][1];
            $markerEnd = $markerStart + strlen((string) $m[0][0]);
        }

        $metaSource = $markerStart === false ? $text : substr($text, 0, $markerStart);
        $synopsis = $markerEnd === false ? null : trim(substr($text, $markerEnd));

        if ($synopsis === '') {
            $synopsis = null;
        }

        $values = [];
        $matches = [];

        if (preg_match_all(self::META_PATTERN, $metaSource, $m2, PREG_OFFSET_CAPTURE) > 0) {
            $matches = $m2[1];
        }

        $count = count($matches);

        for ($i = 0; $i < $count; $i++) {
            $key = (string) $matches[$i][0];
            $valueStart = (int) $matches[$i][1] + strlen($key) + 1;

            while ($valueStart < strlen($metaSource) && $metaSource[$valueStart] === ' ') {
                $valueStart++;
            }

            $valueEnd = $i + 1 < $count ? (int) $matches[$i + 1][1] : strlen($metaSource);
            $value = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', substr($metaSource, $valueStart, max(0, $valueEnd - $valueStart))) ?? '');

            if ($value === '') {
                continue;
            }

            $values[$key] = $value;
        }

        $meta = [];

        foreach (self::META_LABELS as $key => $label) {
            $value = $values[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if (in_array(mb_strtolower($value), self::META_NOISE, true)) {
                continue;
            }

            $meta[] = ['label' => $label, 'value' => $value];
        }

        return ['synopsis' => $synopsis, 'genres' => self::genreList($values), 'meta' => $meta];
    }

    /**
     * Daftar genre bersih (tanpa kunci Tema/Demografis, tanpa nilai noise).
     *
     * @param array<string,string> $values
     * @return array<int,string>
     */
    public static function genreList(array $values): array
    {
        $genres = [];

        if (isset($values['Genres'])) {
            foreach (explode(',', $values['Genres']) as $genre) {
                $genre = trim($genre);

                if ($genre === '' || in_array(mb_strtolower($genre), self::META_NOISE, true)) {
                    continue;
                }

                $canon = self::canonGenre($genre);

                if ($canon === null) {
                    continue;
                }

                $genres[] = $canon;
            }
        }

        return array_values(array_unique($genres));
    }

    /**
     * Genre cadangan dari kolom raw_meta (field `genre` bawaan item upstream).
     * Dipakai bila synopsis tidak memuat baris "Genres:".
     *
     * @return array<int,string>
     */
    public static function genresFromRaw(mixed $rawMeta): array
    {
        $decoded = is_array($rawMeta) ? $rawMeta : json_decode((string) $rawMeta, true);

        if (!is_array($decoded)) {
            return [];
        }

        $raw = $decoded['genre'] ?? null;

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $genres = [];

        foreach (explode(',', $raw) as $token) {
            $token = trim($token);

            if ($token === '' || in_array(mb_strtolower($token), self::META_NOISE, true)) {
                continue;
            }

            $canon = self::canonGenre($token);

            if ($canon !== null) {
                $genres[] = $canon;
            }
        }

        return array_values(array_unique($genres));
    }

    /**
     * Genre langsung dari teks synopsis (jalan pintas untuk backfill).
     *
     * @return array<int,string>
     */
    public static function genres(?string $raw): array
    {
        $text = trim((string) $raw);

        if ($text === '' || preg_match(self::META_PATTERN, $text) !== 1) {
            return [];
        }

        if (preg_match_all(self::META_PATTERN, $text, $m, PREG_OFFSET_CAPTURE) <= 0) {
            return [];
        }

        $values = [];
        $matches = $m[1];
        $count = count($matches);

        for ($i = 0; $i < $count; $i++) {
            $key = (string) $matches[$i][0];

            if ($key !== 'Genres') {
                continue;
            }

            $valueStart = (int) $matches[$i][1] + strlen($key) + 1;

            while ($valueStart < strlen($text) && $text[$valueStart] === ' ') {
                $valueStart++;
            }

            $valueEnd = $i + 1 < $count ? (int) $matches[$i + 1][1] : strlen($text);
            $values['Genres'] = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', substr($text, $valueStart, max(0, $valueEnd - $valueStart))) ?? '');

            break;
        }

        return self::genreList($values);
    }
}
