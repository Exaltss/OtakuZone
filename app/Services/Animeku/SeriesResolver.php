<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Services/Animeku/SeriesResolver.php
 * Fungsi : menurunkan identitas seri/episode dari metadata Animeku
 * Rujuk  : Blueprint_ANIMEKU.md §3, §4; metadata turunan user
 *
 * Kunci identitas:
 * - seri    : cat_id / cid
 * - episode : vid, fallback video_id, fallback id
 * - nomor   : ep / episode / number, fallback parse judul, default 1.00
 */
declare(strict_types=1);

namespace App\Services\Animeku;

use Illuminate\Support\Str;

final class SeriesResolver
{
    /**
     * @param array<string,mixed> $item
     * @return array{
     *     external_id: string,
     *     slug: string,
     *     title: string,
     *     cat_id: int|null,
     *     number: float,
     *     episode_external_id: string
     * }
     */
    public function resolve(array $item): array
    {
        $rawTitle = trim((string) ($item['video_title'] ?? $item['title'] ?? 'Untitled'));
        $catId = $this->intOrNull($item['cat_id'] ?? $item['cid'] ?? null);

        $seriesTitle = trim((string) (
            $item['series_clean']
            ?? $item['series']
            ?? $item['category_name']
            ?? ''
        ));

        if ($seriesTitle === '' || $this->hasEpisodeMarker($seriesTitle)) {
            $derived = $this->deriveSeriesTitle($rawTitle);

            if ($seriesTitle === '' || $derived !== '') {
                $seriesTitle = $derived;
            }
        }

        if ($seriesTitle === '') {
            $seriesTitle = $rawTitle !== '' ? $rawTitle : 'Untitled Series';
        }

        $number = $this->explicitNumber($item);

        if ($number === null) {
            $number = $this->deriveNumber($rawTitle);
        }

        if ($number === null) {
            $number = 1.0;
        }

        $episodeExternalId = $this->episodeExternalId($item);

        if ($catId !== null && $catId > 0) {
            $externalId = 'series:' . $catId;
        } else {
            $key = 'animeku|na|' . Str::slug($seriesTitle);
            $externalId = 'series:' . substr(hash('sha256', $key), 0, 40);
        }

        $slug = Str::slug($seriesTitle);

        if ($slug === '') {
            $slug = $catId !== null ? 'series-' . $catId : 'series-' . substr(hash('sha256', $externalId), 0, 8);
        }

        return [
            'external_id' => $externalId,
            'slug' => $slug,
            'title' => $seriesTitle,
            'cat_id' => $catId,
            'number' => round((float) $number, 2),
            'episode_external_id' => $episodeExternalId,
        ];
    }

    /**
     * @param array<string,mixed> $item
     */
    private function episodeExternalId(array $item): string
    {
        $raw = $item['vid'] ?? $item['video_id'] ?? $item['id'] ?? '';

        if (is_float($raw)) {
            $raw = rtrim(rtrim(number_format($raw, 6, '.', ''), '0'), '.');
        }

        if (is_int($raw)) {
            return (string) $raw;
        }

        if (!is_scalar($raw)) {
            return '';
        }

        return trim((string) $raw);
    }

    private function hasEpisodeMarker(string $title): bool
    {
        return preg_match('/\b(?:eps?|episode|ep)\.?\s*#?\s*\d+/iu', $title) === 1;
    }

    private function deriveSeriesTitle(string $title): string
    {
        $clean = $title;

        $patterns = [
            '/\b(?:eps?|episode|ep)\.?\s*#?\s*\d+(?:[.,]\d+)?\b.*$/iu',
            '/\b\d+\s*(?:sub|indo|dub|batch)\b.*$/iu',
            '/\s*[-–|]\s*(?:sub|indo|dub|batch)\s*$/iu',
            '/\s*(?:\((?:ended|complete|completed)\)|\[(?:ended|complete|completed)\])\s*$/iu',
        ];

        foreach ($patterns as $pattern) {
            $clean = (string) preg_replace($pattern, '', $clean);
        }

        $clean = trim((string) preg_replace('/\s+/', ' ', $clean));
        $clean = trim($clean, " \t\n\r\0\x0B-–|,:");

        return $clean;
    }

    private function deriveNumber(string $title): ?float
    {
        if (preg_match('/\b(?:eps?|episode|ep)\.?\s*#?\s*(\d+(?:[.,]\d+)?)\b/iu', $title, $m) === 1) {
            $number = (float) str_replace(',', '.', $m[1]);

            return $number > 0 && $number <= 999999 ? round($number, 2) : null;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function explicitNumber(array $item): ?float
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

        return null;
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
