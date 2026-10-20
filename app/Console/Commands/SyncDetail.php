<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/SyncDetail.php
 * Fungsi : lazy sync detail Animeku, append-only, strip URL raw_meta
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5.1, §5.4, §2.3
 */
declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UpsertsAnimekuItem;
use App\Models\Anime;
use App\Services\Animeku\AnimekuClient;
use App\Services\Animeku\ResponseMapper;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class SyncDetail extends Command
{
    use UpsertsAnimekuItem;

    protected $signature = 'sync:detail
                            {--anime= : DB anime id atau external_id}
                            {--external= : External id Animeku}
                            {--dry-run : Hanya petakan respons, tidak tulis DB}';

    protected $description = 'Sinkron detail anime/episode dari Animeku secara append-only';

    public function handle(AnimekuClient $client, ResponseMapper $mapper): int
    {
        $selector = trim((string) ($this->option('external') ?: $this->option('anime')));

        if ($selector === '') {
            $this->error('Butuh --anime atau --external.');

            return self::FAILURE;
        }

        $externalId = $selector;
        $anime = null;

        if (ctype_digit($selector)) {
            $anime = Anime::query()->find((int) $selector);

            if ($anime !== null) {
                $externalId = (string) $anime->external_id;
            }
        }

        if ($anime === null) {
            $anime = Anime::query()->where('external_id', $externalId)->first();
        }

        $response = null;
        $lastError = null;

        foreach (['detail', 'post_detail'] as $method) {
            try {
                $response = $method === 'detail'
                    ? $client->detail($externalId)
                    : $client->postDetail($externalId);

                break;
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        if (!is_array($response)) {
            $this->error('Detail kosong: ' . ($lastError?->getMessage() ?? 'unknown'));

            return self::FAILURE;
        }

        $item = $this->normalizeItem($response);

        if ($item === null) {
            $this->error('Respons detail tidak dikenali. Perlu verifikasi V1.');

            return self::FAILURE;
        }

        $mapped = $mapper->mapItem($item);

        if ((bool) $this->option('dry-run')) {
            $this->line(sprintf(
                'DRY_RUN external_id=%s title=%s episode_number=%s',
                (string) ($mapped['anime']['external_id'] ?? ''),
                (string) ($mapped['anime']['title'] ?? ''),
                (string) ($mapped['episode']['number'] ?? '')
            ));

            return self::SUCCESS;
        }

        $saved = $this->upsertItem($item, $mapper);

        $this->info("sync:detail ok anime_id={$saved->id} external_id={$saved->external_id}");

        return self::SUCCESS;
    }

    /**
     * @param array<mixed> $response
     * @return array<string,mixed>|null
     */
    private function normalizeItem(array $response): ?array
    {
        if (isset($response['latest_anime']) && is_array($response['latest_anime']) && $response['latest_anime'] !== []) {
            $first = reset($response['latest_anime']);

            return is_array($first) ? $first : null;
        }

        if (isset($response['data'])) {
            $data = $response['data'];

            if (is_array($data)) {
                if (isset($data['video_title']) || isset($data['vid']) || isset($data['video_id'])) {
                    return $data;
                }

                $first = reset($data);

                return is_array($first) ? $first : null;
            }
        }

        if (isset($response['video_title']) || isset($response['vid']) || isset($response['video_id'])) {
            return $response;
        }

        return null;
    }
}
