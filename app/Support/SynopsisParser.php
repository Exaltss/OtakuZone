<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Support/SynopsisParser.php
 * Fungsi : memisahkan blok metadata MAL dari sinopsis + ekstraksi daftar genre
 * Rujuk  : dipakai AnimeController (detail), GenreController, zo:backfill-genres
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
     * Whitelist genre kanonikal: gabungan genre MAL lama + baru + seluruh tema +
     * demografis. Token di luar daftar ini dianggap noise dan DILAPORKAN oleh
     * zo:backfill-genres supaya tidak ada genre sah yang hilang diam-diam.
     */
    private const GENRE_CANON = [
        // genre MAL terkini
        'Action', 'Adventure', 'Comedy', 'Drama', 'Fantasy', 'Horror', 'Mystery',
        'Romance', 'Sci-Fi', 'Slice of Life', 'Sports', 'Supernatural', 'Suspense',
        'Ecchi', 'Erotica', 'Hentai', 'Kids', 'Seinen', 'Shoujo', 'Shounen', 'Josei',
        'Boys Love', 'Girls Love', 'Avant Garde', 'Gourmet', 'Award Winning',
        // genre MAL lama yang masih dipakai upstream
        'Magic', 'Game', 'Games', 'Demons', 'Super Power', 'Martial Arts', 'Police',
        'Military', 'Music', 'Psychological', 'Samurai', 'School', 'Space', 'Thriller',
        'Tokusatsu', 'Vampire', 'Mecha', 'Cars', 'Dementia', 'Adult', 'Yaoi', 'Yuri',
        'Shounen Ai', 'Shoujo Ai', 'Harem', 'Historical', 'Parody', 'Isekai',
        // tema MAL (lengkap)
        'Adult Cast', 'Anthology', 'CGDCT', 'Childcare', 'Combat Sports',
        'Crossdressing', 'Delinquents', 'Detective', 'Educational', 'Gag Humor',
        'Gore', 'Idols', 'Love Polygon', 'Magical Sex Shift', 'Mahou Shoujo',
        'Medical', 'Myths & Mythology', 'Organized Crime', 'Otaku Culture',
        'Performing Arts', 'Pets', 'Racing', 'Reincarnation', 'Reverse Harem',
        'Romantic Subtext', 'Showbiz', 'Strategy Game', 'Time Travel', 'Video Game',
        'Visual Arts', 'Workplace',
    ];

    private static function normGenre(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/\s*-\s*/', '-', $value);
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return $value;
    }

    /**
     * Petakan token genre bebas ke nama kanonikal; null bila tidak dikenal.
     */
    public static function canonGenre(string $token): ?string
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

        $values = self::metaValues($metaSource);

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

        return ['synopsis' => $synopsis, 'genres' => self::keep(self::cleanTokens($values['Genres'] ?? null)), 'meta' => $meta];
    }

    /**
     * Genre kanonikal dari teks synopsis.
     *
     * @return array<int,string>
     */
    public static function genres(?string $raw): array
    {
        return self::keep(self::tokensFromSynopsis($raw));
    }

    /**
     * Genre kanonikal cadangan dari kolom raw_meta (field `genre` item upstream).
     *
     * @param mixed $rawMeta array hasil cast JSON, atau string JSON mentah
     * @return array<int,string>
     */
    public static function genresFromRaw(mixed $rawMeta): array
    {
        return self::keep(self::tokensFromRaw($rawMeta));
    }

    /**
     * Token genre mentah (sebelum pemetaan kanonikal) dari synopsis, fallback raw_meta.
     * Dipakai zo:backfill-genres untuk melaporkan token yang tidak dikenali.
     *
     * @return array<int,string>
     */
    public static function genreTokens(?string $synopsis, mixed $rawMeta): array
    {
        $tokens = self::tokensFromSynopsis($synopsis);

        if ($tokens === []) {
            $tokens = self::tokensFromRaw($rawMeta);
        }

        return $tokens;
    }

    /**
     * @return array<int,string>
     */
    private static function tokensFromSynopsis(?string $raw): array
    {
        $text = trim((string) $raw);

        if ($text === '' || preg_match(self::META_PATTERN, $text) !== 1) {
            return [];
        }

        // potong di marker sinopsis supaya nilai Genres tidak menelan paragraf cerita
        if (preg_match('/[\s\x{00A0}]+S[iny]nopsis[\s\x{00A0}]+/u', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $text = substr($text, 0, (int) $m[0][1]);
        }

        $values = self::metaValues($text);

        return self::cleanTokens($values['Genres'] ?? null);
    }

    /**
     * @return array<int,string>
     */
    private static function tokensFromRaw(mixed $rawMeta): array
    {
        $decoded = is_array($rawMeta) ? $rawMeta : json_decode((string) $rawMeta, true);

        if (!is_array($decoded)) {
            return [];
        }

        $raw = $decoded['genre'] ?? null;

        if (!is_string($raw)) {
            return [];
        }

        return self::cleanTokens($raw);
    }

    /**
     * Pecah string daftar genre jadi token bersih (belum dipetakan kanonikal).
     *
     * @return array<int,string>
     */
    private static function cleanTokens(?string $list): array
    {
        $tokens = [];

        foreach (explode(',', (string) $list) as $token) {
            $token = trim($token);

            if ($token === '' || in_array(mb_strtolower($token), self::META_NOISE, true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * @param array<int,string> $tokens
     * @return array<int,string>
     */
    private static function keep(array $tokens): array
    {
        $kept = [];

        foreach ($tokens as $token) {
            $canon = self::canonGenre($token);

            if ($canon !== null) {
                $kept[] = $canon;
            }
        }

        return array_values(array_unique($kept));
    }

    /**
     * Ekstraksi pasangan kunci=>nilai dari blok metadata MAL.
     *
     * @return array<string,string>
     */
    private static function metaValues(string $metaSource): array
    {
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

        return $values;
    }
}
