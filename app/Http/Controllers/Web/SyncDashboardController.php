<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Controllers/Web/SyncDashboardController.php
 * Fungsi : dashboard QA lokal untuk statistik katalog, episode, sync state, dan host probe sanitized
 */
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class SyncDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $animeStat = DB::table('anime')->selectRaw("
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) AS ongoing,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN year IS NULL THEN 1 ELSE 0 END) AS no_year,
            SUM(CASE WHEN cat_id IS NULL THEN 1 ELSE 0 END) AS no_cat_id
        ")->first();

        $episodeStat = DB::table('episodes')->selectRaw('
            COUNT(*) AS total,
            SUM(CASE WHEN quality_urls IS NOT NULL AND quality_urls != "" THEN 1 ELSE 0 END) AS with_stream,
            SUM(CASE WHEN quality_urls IS NULL OR quality_urls = "" THEN 1 ELSE 0 END) AS without_stream,
            MAX(number) AS max_number,
            SUM(CASE WHEN number > 999 THEN 1 ELSE 0 END) AS gt_999
        ')->first();

        $activeAnime = (int) DB::table('episodes')->count(DB::raw('DISTINCT anime_id'));
        $animeTotal = (int) ($animeStat->total ?? 0);
        $episodeTotal = (int) ($episodeStat->total ?? 0);

        $global = [
            'anime_total' => $animeTotal,
            'anime_ongoing' => (int) ($animeStat->ongoing ?? 0),
            'anime_completed' => (int) ($animeStat->completed ?? 0),
            'anime_no_year' => (int) ($animeStat->no_year ?? 0),
            'anime_no_cat_id' => (int) ($animeStat->no_cat_id ?? 0),
            'episode_total' => $episodeTotal,
            'episode_with_stream' => (int) ($episodeStat->with_stream ?? 0),
            'episode_without_stream' => (int) ($episodeStat->without_stream ?? 0),
            'anime_with_episodes' => $activeAnime,
            'anime_without_episodes' => max(0, $animeTotal - $activeAnime),
            'max_episode_number' => (float) ($episodeStat->max_number ?? 0),
            'episodes_gt_999' => (int) ($episodeStat->gt_999 ?? 0),
            'avg_episodes_per_active_anime' => $activeAnime > 0 ? round($episodeTotal / $activeAnime, 2) : 0.0,
            'stream_integrity' => (int) ($episodeStat->without_stream ?? 0) === 0 ? 'OK' : 'CHECK',
        ];

        $states = [];

        foreach (DB::table('sync_state')->orderByDesc('last_run_at')->limit(20)->get() as $row) {
            $rawMeta = $row->meta ?? null;

            if (is_array($rawMeta)) {
                $meta = $rawMeta;
            } else {
                $meta = json_decode((string) ($rawMeta ?? '{}'), true);
            }

            if (!is_array($meta)) {
                $meta = [];
            }

            $stats = is_array($meta['stats'] ?? null) ? $meta['stats'] : [];

            $states[] = [
                'job_key' => (string) ($row->job_key ?? '-'),
                'last_page_ok' => (int) ($row->last_page_ok ?? 0),
                'cursor' => (string) ($row->cursor ?? '-'),
                'last_status' => (string) ($row->last_status ?? '-'),
                'last_run_at' => (string) ($row->last_run_at ?? '-'),
                'mode' => (string) ($meta['mode'] ?? '-'),
                'identity' => (string) ($meta['identity'] ?? '-'),
                'total_cids' => $meta['total_cids'] ?? null,
                'items' => (int) ($stats['items'] ?? 0),
                'ep_new' => (int) ($stats['ep_new'] ?? 0),
                'ep_existing' => (int) ($stats['ep_existing'] ?? 0),
                'skipped' => (int) ($stats['skipped'] ?? 0),
                'empty_details' => (int) ($stats['empty_details'] ?? 0),
                'detail_errors' => (int) ($stats['detail_errors'] ?? 0),
                'error' => $meta['error'] ?? null,
            ];
        }

        $reportPath = base_path('storage/app/sync/all-host-stream-probe.json');
        $report = [];

        if (is_file($reportPath)) {
            $decoded = json_decode((string) file_get_contents($reportPath), true);

            if (is_array($decoded)) {
                $report = $decoded;
            }
        }

        $reportStats = is_array($report['stats'] ?? null) ? $report['stats'] : [];
        $reportSummary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $reportHosts = is_array($report['hosts'] ?? null) ? $report['hosts'] : [];

        $files = [];

        foreach ([
            'detail_cids' => 'storage/app/sync/detail-cids.json',
            'missing_cids' => 'storage/app/sync/missing-cids.txt',
            'host_probe' => 'storage/app/sync/all-host-stream-probe.json',
            'stream_hosts' => 'storage/app/sync/stream-hosts.json',
        ] as $label => $path) {
            $full = base_path($path);
            $exists = is_file($full);

            $files[$label] = [
                'path' => $path,
                'exists' => $exists,
                'size' => $exists ? (int) filesize($full) : 0,
                'updated_at' => $exists ? date('Y-m-d H:i:s', (int) filemtime($full)) : null,
            ];
        }

        $missingCids = [];
        $missingPath = base_path('storage/app/sync/missing-cids.txt');

        if (is_file($missingPath)) {
            $raw = trim((string) file_get_contents($missingPath));

            if ($raw !== '') {
                foreach (explode(',', $raw) as $cid) {
                    $cid = (int) trim($cid);

                    if ($cid > 0) {
                        $missingCids[] = $cid;
                    }
                }
            }
        }

        return Inertia::render('Sync/Dashboard', [
            'global' => $global,
            'syncStates' => $states,
            'reportStats' => $reportStats,
            'reportSummary' => $reportSummary,
            'reportHosts' => $reportHosts,
            'files' => $files,
            'missingCids' => $missingCids,
        ]);
    }
}
