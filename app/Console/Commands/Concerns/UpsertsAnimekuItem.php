<?php
declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\Anime;
use App\Models\Episode;
use App\Services\Animeku\ResponseMapper;
use App\Services\Animeku\SeriesResolver;
use Illuminate\Support\Str;
use RuntimeException;

trait UpsertsAnimekuItem
{
    protected function upsertItem(array $item, ResponseMapper $mapper): Anime
    {
        $resolver = app(SeriesResolver::class);
        $series = $resolver->resolve($item);
        $mapped = $mapper->mapItem($item);

        $animeAttrs = $mapped['anime'];
        $episodeAttrs = $mapped['episode'];

        $seriesExternalId = trim((string) ($series['external_id'] ?? ''));
        $catId = isset($series['cat_id']) ? (int) $series['cat_id'] : null;

        if ($seriesExternalId === '') {
            throw new RuntimeException('missing_series_external_id');
        }

        $anime = null;

        if ($catId !== null && $catId > 0) {
            $anime = Anime::query()
                ->where('source', 'animeku')
                ->where('cat_id', $catId)
                ->orderByDesc('episodes_total')
                ->orderBy('id')
                ->first();
        }

        if ($anime === null) {
            $anime = Anime::query()->firstOrNew([
                'source' => 'animeku',
                'external_id' => $seriesExternalId,
            ]);
        }

        if (!$anime->exists || !$anime->slug) {
            $anime->slug = $this->uniqueSlug((string) ($series['slug'] ?? ''), $seriesExternalId);
            $anime->episodes_total = (int) ($anime->episodes_total ?? 0);
            $anime->popularity_score = (int) ($anime->popularity_score ?? 0);
            $anime->is_hidden = false;
        }

        $anime->title = (string) ($series['title'] ?? $animeAttrs['title'] ?? 'Untitled');

        if ($catId !== null && $catId > 0) {
            $anime->cat_id = $catId;
        }

        foreach (['synopsis', 'type', 'status', 'year', 'season', 'rating_avg', 'view_count', 'raw_meta'] as $field) {
            $val = $animeAttrs[$field] ?? null;
            if ($val !== null && $val !== '' && $val !== []) {
                $anime->{$field} = $val;
            }
        }

        $anime->synced_at = now();
        $anime->save();

        $qualityUrls = $episodeAttrs['quality_urls'] ?? [];
        $epExternalId = trim((string) ($series['episode_external_id'] ?? $episodeAttrs['external_id'] ?? ''));
        $number = round((float) ($series['number'] ?? $episodeAttrs['number'] ?? 1.0), 2);

        if ($epExternalId !== '' && is_array($qualityUrls) && $qualityUrls !== []) {
            $episode = Episode::query()
                ->where('anime_id', $anime->id)
                ->where('external_id', $epExternalId)
                ->first();

            if ($episode === null) {
                $episode = Episode::query()->firstOrNew([
                    'anime_id' => $anime->id,
                    'number' => $number,
                ]);
            }

            $isNewEp = !$episode->exists;
            $episode->external_id = $epExternalId;
            $episode->title = (string) ($episodeAttrs['title'] ?? 'Episode ' . $number);
            $episode->thumbnail_path = $episodeAttrs['thumbnail_path'] ?? $episode->thumbnail_path;
            $episode->duration_sec = $episodeAttrs['duration_sec'] ?? $episode->duration_sec;
            $episode->quality_urls = $qualityUrls;
            $episode->is_hidden = (bool) ($episode->is_hidden ?? false);
            $episode->synced_at = now();
            $episode->save();

            if ($isNewEp) {
                $anime->episodes_total = Episode::query()->where('anime_id', $anime->id)->count();
                $anime->save();
            }
        }

        return $anime;
    }

    private function uniqueSlug(string $slug, string $externalId): string
    {
        $base = $slug !== '' ? $slug : Str::slug('anime-' . $externalId);
        $base = Str::limit($base, 240, '');
        $candidate = $base;
        $suffix = substr(hash('sha256', $externalId), 0, 6);

        if ($this->slugTakenByOther($candidate, $externalId)) {
            $candidate = Str::limit($base . '-' . $suffix, 260, '');
        }

        $i = 1;
        while ($this->slugTakenByOther($candidate, $externalId) && $i <= 5) {
            $candidate = Str::limit($base . '-' . $suffix . '-' . $i, 260, '');
            $i++;
        }

        return $candidate;
    }

    private function slugTakenByOther(string $slug, string $externalId): bool
    {
        return Anime::query()
            ->where('slug', $slug)
            ->where('external_id', '!=', $externalId)
            ->exists();
    }
}
