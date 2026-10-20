<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Services/Animeku/ResponseMapper.php
 * Fungsi : memetakan item API Animeku dan metadata turunan ke kolom anime/episode
 * Rujuk  : Blueprint_ANIMEKU.md §3, §4, §5; metadata turunan user
 */
declare(strict_types=1);

namespace App\Services\Animeku;

use Illuminate\Support\Str;

final class ResponseMapper
{
    /**
     * @var array<string,string>
     */
    private const QUALITY_MAP = [
        'video_url' => '360',
        'video_url_minihd' => '480',
        'video_url_hd' => '720',
        'video_url_fullhd' => '1080',
        'video_url_eng' => 'eng',
        'video_url_hdeng' => 'hdeng',
        'url_360' => '360',
        'url_480' => '480',
        'url_720' => '720',
        'url_1080' => '1080',
        'url_eng' => 'eng',
        'url_auto' => 'auto',
    ];

    /**
     * @param array<string,mixed> $item
     * @return array{anime:array<string,mixed>, episode:array<string,mixed>}
     */
    public function mapItem(array $item): array
    {
        $externalId = trim((string) ($item['vid'] ?? $item['video_id'] ?? $item['id'] ?? ''));
        $title = trim((string) ($item['video_title'] ?? $item['title'] ?? 'Untitled'));
        $slug = Str::slug($title) ?: 'item-' . substr(hash('sha256', $externalId !== '' ? $externalId : $title), 0, 8);

        $qualityUrls = $this->extractQualityUrls($item);
        $rawMeta = $this->stripUrls($item);

        $durationRaw = trim((string) ($item['video_duration'] ?? $item['duration'] ?? ''));
        $descriptionRaw = trim((string) ($item['video_description'] ?? $item['description'] ?? ''));

        $anime = [
            'external_id' => $externalId,
            'cat_id' => $this->intOrNull($item['cat_id'] ?? $item['cid'] ?? null),
            'title' => $title,
            'slug' => $slug,
            'image_path' => null,
            'synopsis' => $this->stripUrlText($this->plainText($descriptionRaw)),
            'type' => $this->deriveType($item, $descriptionRaw),
            'status' => $this->deriveStatus($item, $durationRaw, $descriptionRaw),
            'year' => $this->deriveYear($item, $descriptionRaw),
            'season' => null,
            'episodes_total' => null,
            'rating_avg' => $this->deriveRating($item),
            'view_count' => $this->deriveViewCount($item),
            'popularity_score' => 0,
            'source' => 'animeku',
            'raw_meta' => $rawMeta,
            'synced_at' => now(),
        ];

        $episode = [
            'external_id' => $externalId,
            'number' => $this->deriveEpisodeNumber($item, $title),
            'title' => $title,
            'thumbnail_path' => $this->deriveThumbnail($item),
            'duration_sec' => $this->deriveDurationSec($durationRaw, $descriptionRaw),
            'quality_urls' => $qualityUrls,
            'is_hidden' => false,
            'synced_at' => now(),
        ];

        return ['anime' => $anime, 'episode' => $episode];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,string>
     */
    public function extractQualityUrls(array $item): array
    {
        $urls = [];

        foreach ($item as $key => $value) {
            if (!is_string($key) || !is_string($value) || $value === '') {
                continue;
            }

            if ($key === 'raw_key') {
                continue;
            }

            $qualityKey = null;

            if (isset(self::QUALITY_MAP[$key])) {
                $qualityKey = self::QUALITY_MAP[$key];
            } elseif (str_starts_with($key, 'video_url')) {
                $suffix = substr($key, strlen('video_url'));
                $suffix = ltrim($suffix, '_');
                $qualityKey = $suffix === '' ? '360' : $suffix;
            } elseif (str_starts_with($key, 'url_')) {
                $qualityKey = substr($key, strlen('url_'));
            }

            if ($qualityKey === null || $qualityKey === '') {
                continue;
            }

            if (!filter_var($value, FILTER_VALIDATE_URL)) {
                continue;
            }

            $urls[$qualityKey] = $value;
        }

        return $urls;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function deriveType(array $item, string $description = ''): ?string
    {
        $fromText = $this->typeFromText($description);

        if ($fromText !== null) {
            return $fromText;
        }

        $value = $item['video_type'] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $known = ['tv', 'movie', 'ova', 'ona', 'special', 'music'];

        return $this->typeFromText($value)
            ?? (in_array(strtolower($value), $known, true) ? ucfirst(strtolower($value)) : null);
    }

    private function typeFromText(string $text): ?string
    {
        if (preg_match('/Information\s+Type:\s*([A-Za-z]+)/i', $text, $m) === 1) {
            $candidate = strtolower($m[1]);
        } elseif (preg_match('/\bType:\s*(Movie|TV|OVA|ONA|Special|Music)\b/i', $text, $m) === 1) {
            $candidate = strtolower($m[1]);
        } else {
            $candidate = '';
        }

        return match ($candidate) {
            'movie' => 'Movie',
            'tv' => 'TV',
            'ova' => 'OVA',
            'ona' => 'ONA',
            'special' => 'Special',
            'music' => 'Music',
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $item
     */
    private function deriveStatus(array $item, string $duration, string $description): ?string
    {
        $explicit = $item['status_video'] ?? null;

        if (is_string($explicit) && trim($explicit) !== '') {
            $value = strtolower(trim($explicit));

            if (str_contains($value, 'ongoing')) {
                return 'ongoing';
            }

            if (str_contains($value, 'complete') || str_contains($value, 'finished')) {
                return 'completed';
            }

            return Str::limit($value, 30, '');
        }

        $duration = strtolower(trim($duration));

        if ($duration !== '') {
            if (str_contains($duration, 'ongoing')) {
                return 'ongoing';
            }

            if (str_contains($duration, 'complete') || str_contains($duration, 'finished')) {
                return 'completed';
            }
        }

        $description = strtolower($description);

        if (str_contains($description, 'currently airing') || str_contains($description, 'ongoing')) {
            return 'ongoing';
        }

        if (str_contains($description, 'finished') || str_contains($description, 'completed')) {
            return 'completed';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function deriveYear(array $item, string $description): ?int
    {
        $value = $item['year'] ?? null;

        if (is_numeric($value)) {
            $year = (int) $value;

            if ($year >= 1900 && $year <= 2100) {
                return $year;
            }
        }

        if (is_string($value) && preg_match('/\b((?:19|20)\d{2})\b/', $value, $m) === 1) {
            $year = (int) $m[1];

            if ($year >= 1900 && $year <= 2100) {
                return $year;
            }
        }

        if (preg_match('/\bAired:\s*[^\n]{0,120}?\b((?:19|20)\d{2})\b/i', $description, $m) === 1) {
            $year = (int) $m[1];

            if ($year >= 1900 && $year <= 2100) {
                return $year;
            }
        }

        if (preg_match('/\bPremiered:\s*[^\n]{0,80}?\b((?:19|20)\d{2})\b/i', $description, $m) === 1) {
            $year = (int) $m[1];

            if ($year >= 1900 && $year <= 2100) {
                return $year;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function deriveRating(array $item): ?float
    {
        $value = $item['rating'] ?? null;

        if (is_string($value)) {
            $value = trim($value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        $rating = (float) $value;

        if ($rating < 0) {
            $rating = 0;
        }

        if ($rating > 9.99) {
            $rating = 9.99;
        }

        return round($rating, 2);
    }

    /**
     * @param array<string,mixed> $item
     */
    private function deriveViewCount(array $item): ?int
    {
        $value = $item['total_views'] ?? null;

        if (is_string($value)) {
            $value = trim($value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        $count = (int) $value;

        if ($count < 0) {
            return 0;
        }

        if ($count > 4294967295) {
            return 4294967295;
        }

        return $count;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function deriveThumbnail(array $item): ?string
    {
        $value = $item['video_thumbnail'] ?? $item['thumb'] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, 255, '');
    }

    private function deriveDurationSec(string $duration, string $description): ?int
    {
        if (preg_match('/([0-9]+)\s*(?:menit|minute|m\b)/i', $duration, $m) === 1) {
            return ((int) $m[1]) * 60;
        }

        if (preg_match('/([0-9]+):([0-9]{2})/', $duration, $m) === 1) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }

        if (preg_match('/Duration:\s*([0-9]+)\s*(?:min|minutes)/i', $description, $m) === 1) {
            return ((int) $m[1]) * 60;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function deriveEpisodeNumber(array $item, string $title): ?float
    {
        foreach (['ep', 'episode', 'episode_number', 'number'] as $key) {
            if (!isset($item[$key])) {
                continue;
            }

            $value = $item[$key];

            if (is_string($value)) {
                $value = str_replace(',', '.', trim($value));
            }

            if (!is_numeric($value)) {
                continue;
            }

            $number = (float) $value;

            if ($number > 0 && $number <= 999999) {
                return round($number, 2);
            }
        }

        if (preg_match('/\b(?:eps?|episode|ep)\.?\s*#?\s*(\d+(?:[.,]\d+)?)\b/iu', $title, $m) === 1) {
            $number = (float) str_replace(',', '.', $m[1]);

            return $number > 0 && $number <= 999999 ? round($number, 2) : 1.0;
        }

        return 1.0;
    }

    private function plainText(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    private function stripUrlText(string $text): string
    {
        return (string) preg_replace('#(?:https?:)?//[^\s<>"\'`]+#i', '[STRIPPED_URL]', $text);
    }

    /**
     * @param array<mixed>|mixed $value
     * @return mixed
     */
    private function stripUrls(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->stripUrls($v);
            }

            return $value;
        }

        if (is_string($value)) {
            return $this->stripUrlText($value);
        }

        return $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
