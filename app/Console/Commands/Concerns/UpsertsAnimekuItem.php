<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/Concerns/UpsertsAnimekuItem.php
 * Fungsi : upsert anime/episode dari item Animeku, append-only, slug collision guard
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5.4, §5.6
 */
declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\Anime;
use App\Models\Episode;
use App\Services\Animeku\ResponseMapper;
use Illuminate\Support\Str;
use RuntimeException;

trait UpsertsAnimekuItem
{
    protected function upsertItem(array $item, ResponseMapper $mapper): Anime
    {
        $mapped = $mapper->mapItem($item);

        $animeAttrs = $mapped['anime'];
        $episodeAttrs = $mapped['episode'];

        $externalId = trim((string) ($animeAttrs['external_id'] ?? ''));

        if ($externalId === '') {
            throw new RuntimeException('missing_external_id');
        }

        $anime = Anime::query()->firstOrNew([
            'source' => 'animeku',
            'external_id' => $externalId,
        ]);

        if (!$anime->exists || !$anime->slug) {
            $anime->slug = $this->uniqueSlug((string) ($animeAttrs['slug'] ?? ''), $externalId);
        }

        unset($animeAttrs['slug'], $animeAttrs['external_id'], $animeAttrs['source']);

        $anime->fill($animeAttrs);
        $anime->synced_at = now();
        $anime->save();

        $number = (float) ($episodeAttrs['number'] ?? 1);

        $episode = Episode::query()->firstOrNew([
            'anime_id' => $anime->id,
            'number' => $number,
        ]);

        unset($episodeAttrs['anime_id'], $episodeAttrs['number']);

        $episode->fill($episodeAttrs);
        $episode->synced_at = now();
        $episode->save();

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

        if ($this->slugTakenByOther($candidate, $externalId)) {
            $candidate = Str::limit(
                $base . '-' . substr(hash('sha256', $externalId . microtime()), 0, 8),
                260,
                ''
            );
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
