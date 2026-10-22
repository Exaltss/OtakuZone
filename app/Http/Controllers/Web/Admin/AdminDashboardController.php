<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Anime;
use App\Models\AuditLog;
use App\Models\Episode;
use App\Models\ErrorEvent;
use App\Models\FilterRule;
use App\Models\Group;
use App\Models\Setting;
use App\Models\SyncState;
use App\Models\User;
use App\Services\Animeku\AnimekuClient;
use App\Services\Animeku\ResolverChain;
use App\Services\Security\AuditWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class AdminDashboardController extends Controller
{
    public function index(ResolverChain $resolver): Response
    {
        $animeStats = DB::table('anime')->selectRaw("
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) AS ongoing,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN is_hidden = 1 THEN 1 ELSE 0 END) AS hidden,
            SUM(CASE WHEN merged_into_id IS NOT NULL THEN 1 ELSE 0 END) AS merged
        ")->first();

        $episodeStats = DB::table('episodes')->selectRaw("
            COUNT(*) AS total,
            SUM(CASE WHEN quality_urls IS NOT NULL AND quality_urls != '' THEN 1 ELSE 0 END) AS with_stream
        ")->first();

        $sourceConfig = [
            'base_url_primary' => (string) Setting::get('animeku.base_url_primary', config('zonaotaku.animeku.base_url_primary')),
            'base_url_fallback_1' => (string) Setting::get('animeku.base_url_fallback_1', config('zonaotaku.animeku.base_url_fallback_1', '')),
            'base_url_fallback_2' => (string) Setting::get('animeku.base_url_fallback_2', config('zonaotaku.animeku.base_url_fallback_2', '')),
            'worker_relay_url' => (string) Setting::get('animeku.worker_relay_url', config('zonaotaku.animeku.worker_relay_url', '')),
            'stream_header_profile' => (string) Setting::get('animeku.stream_header_profile', config('zonaotaku.animeku.stream_header_profile', 'default')),
            'has_api_key' => ((string) Setting::get('animeku.api_key', config('zonaotaku.animeku.api_key', ''))) !== '',
        ];

        $syncStates = SyncState::query()
            ->orderByDesc('last_run_at')
            ->limit(12)
            ->get(['job_key', 'last_page_ok', 'cursor', 'last_status', 'last_run_at']);

        $users = User::query()
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'name', 'email', 'role', 'banned_at', 'last_login_at', 'created_at']);

        $groups = Group::query()
            ->withCount('items')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'name', 'slug', 'description', 'rule_json']);

        $filterRules = FilterRule::query()
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'name', 'scope', 'condition_json', 'is_active', 'priority']);

        $auditLogs = AuditLog::query()
            ->orderByDesc('id')
            ->limit(40)
            ->get(['id', 'user_id', 'action', 'target_type', 'target_id', 'ip_hash', 'meta', 'created_at']);

        $errorEvents = ErrorEvent::query()
            ->orderByDesc('last_at')
            ->limit(20)
            ->get(['id', 'level', 'message', 'fingerprint', 'count', 'last_at']);

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'anime_total' => (int) ($animeStats->total ?? 0),
                'anime_ongoing' => (int) ($animeStats->ongoing ?? 0),
                'anime_completed' => (int) ($animeStats->completed ?? 0),
                'anime_hidden' => (int) ($animeStats->hidden ?? 0),
                'anime_merged' => (int) ($animeStats->merged ?? 0),
                'episode_total' => (int) ($episodeStats->total ?? 0),
                'episode_with_stream' => (int) ($episodeStats->with_stream ?? 0),
                'users_total' => User::query()->count(),
            ],
            'breakerStatuses' => $resolver->statuses(),
            'sourceConfig' => $sourceConfig,
            'syncStates' => $syncStates,
            'users' => $users,
            'groups' => $groups,
            'filterRules' => $filterRules,
            'auditLogs' => $auditLogs,
            'errorEvents' => $errorEvents,
        ]);
    }

    public function updateSourceConfig(Request $request, AuditWriter $audit): RedirectResponse
    {
        $data = $request->validate([
            'base_url_primary' => ['required', 'url'],
            'base_url_fallback_1' => ['nullable', 'url'],
            'base_url_fallback_2' => ['nullable', 'url'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'worker_relay_url' => ['nullable', 'url'],
            'stream_header_profile' => ['required', 'string', 'max:40'],
        ]);

        Setting::set('animeku.base_url_primary', trim((string) $data['base_url_primary']));
        Setting::set('animeku.base_url_fallback_1', trim((string) ($data['base_url_fallback_1'] ?? '')));
        Setting::set('animeku.base_url_fallback_2', trim((string) ($data['base_url_fallback_2'] ?? '')));
        Setting::set('animeku.worker_relay_url', trim((string) ($data['worker_relay_url'] ?? '')));
        Setting::set('animeku.stream_header_profile', trim((string) $data['stream_header_profile']));

        if (!empty($data['api_key'])) {
            Setting::set('animeku.api_key', trim((string) $data['api_key']));
        }

        $audit->write('admin.source_config_change', (int) $request->user()->id, 'Setting', null, [
            'primary' => $data['base_url_primary'],
            'profile' => $data['stream_header_profile'],
        ], $request);

        return back()->with('success', 'Konfigurasi Source Animeku (hot-swap) berhasil disimpan terenkripsi.');
    }

    public function testUpstream(Request $request, AnimekuClient $client): RedirectResponse
    {
        try {
            $results = $client->testConnection();
            $summary = collect($results)->map(static fn (array $r): string => sprintf(
                '%s: %s (%dms)',
                (string) ($r['base_url'] ?? '-'),
                ($r['ok'] ?? false) ? 'OK' : 'FAIL',
                (int) ($r['ms'] ?? 0)
            ))->implode(' | ');

            return back()->with('success', 'Hasil Probe Upstream: ' . ($summary ?: 'Tidak ada URL.'));
        } catch (Throwable $e) {
            return back()->with('error', 'Probe gagal: ' . $e->getMessage());
        }
    }

    public function moveEpisode(Request $request, AuditWriter $audit): RedirectResponse
    {
        $data = $request->validate([
            'episode_id' => ['required', 'integer', 'min:1'],
            'target_anime_id' => ['required', 'integer', 'min:1'],
            'new_number' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $episode = Episode::query()->findOrFail((int) $data['episode_id']);
        $targetAnime = Anime::query()->findOrFail((int) $data['target_anime_id']);
        $fromAnimeId = (int) $episode->anime_id;
        $desiredNumber = isset($data['new_number']) && $data['new_number'] !== null && $data['new_number'] !== ''
            ? round((float) $data['new_number'], 2)
            : round((float) $episode->number, 2);

        $collision = Episode::query()
            ->where('anime_id', $targetAnime->id)
            ->where('number', $desiredNumber)
            ->where('id', '!=', $episode->id)
            ->exists();

        if ($collision) {
            return back()->with('error', "Nomor episode {$desiredNumber} sudah dipakai di anime tujuan #{$targetAnime->id}.");
        }

        DB::transaction(function () use ($episode, $targetAnime, $fromAnimeId, $desiredNumber): void {
            $episode->anime_id = (int) $targetAnime->id;
            $episode->number = $desiredNumber;
            $episode->save();

            DB::table('anime')->where('id', $fromAnimeId)->update([
                'episodes_total' => DB::table('episodes')->where('anime_id', $fromAnimeId)->count(),
            ]);
            DB::table('anime')->where('id', $targetAnime->id)->update([
                'episodes_total' => DB::table('episodes')->where('anime_id', $targetAnime->id)->count(),
            ]);
        });

        $audit->write('admin.move_episode', (int) $request->user()->id, 'Episode', (int) $episode->id, [
            'from_anime_id' => $fromAnimeId,
            'to_anime_id' => (int) $targetAnime->id,
            'number' => $desiredNumber,
            'reason' => $data['reason'] ?? 'manual_move',
        ], $request);

        return back()->with('success', "Episode #{$episode->id} berhasil dipindahkan ke {$targetAnime->title}.");
    }

    public function mergeAnime(Request $request, AuditWriter $audit): RedirectResponse
    {
        $data = $request->validate([
            'source_anime_id' => ['required', 'integer', 'min:1'],
            'target_anime_id' => ['required', 'integer', 'min:1', 'different:source_anime_id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $source = Anime::query()->findOrFail((int) $data['source_anime_id']);
        $target = Anime::query()->findOrFail((int) $data['target_anime_id']);

        DB::transaction(function () use ($source, $target): void {
            $sourceEpisodes = Episode::query()->where('anime_id', $source->id)->orderBy('number')->get();

            foreach ($sourceEpisodes as $ep) {
                $targetNumber = (float) $ep->number;
                while (Episode::query()->where('anime_id', $target->id)->where('number', $targetNumber)->exists()) {
                    $targetNumber = round($targetNumber + 0.01, 2);
                }

                $ep->anime_id = (int) $target->id;
                $ep->number = $targetNumber;
                $ep->save();
            }

            $source->is_hidden = true;
            $source->merged_into_id = (int) $target->id;
            $source->episodes_total = 0;
            $source->save();

            $target->episodes_total = Episode::query()->where('anime_id', $target->id)->count();
            $target->save();
        });

        $audit->write('admin.merge_anime', (int) $request->user()->id, 'Anime', (int) $source->id, [
            'source_anime_id' => (int) $source->id,
            'target_anime_id' => (int) $target->id,
            'reason' => $data['reason'] ?? 'duplicate_merge',
        ], $request);

        return back()->with('success', "Anime #{$source->id} ({$source->title}) berhasil di-merge (tombstone) ke #{$target->id} ({$target->title}).");
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $slug = Str::slug($data['name']) ?: 'group-' . Str::random(6);

        Group::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'rule_json' => [],
            ]
        );

        return back()->with('success', "Group '{$data['name']}' berhasil disimpan.");
    }

    public function storeFilterRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'scope' => ['required', 'in:global,page,genre'],
            'priority' => ['required', 'integer', 'min:0', 'max:999'],
            'exclude_cat_ids' => ['nullable', 'string'],
        ]);

        $excludeCats = array_values(array_filter(
            array_map('intval', explode(',', (string) ($data['exclude_cat_ids'] ?? ''))),
            static fn (int $v): bool => $v > 0
        ));

        FilterRule::create([
            'name' => $data['name'],
            'scope' => $data['scope'],
            'priority' => (int) $data['priority'],
            'is_active' => true,
            'condition_json' => [
                'exclude_cat_id' => $excludeCats,
            ],
        ]);

        return back()->with('success', "Filter rule '{$data['name']}' berhasil ditambahkan.");
    }

    public function storeUser(Request $request, AuditWriter $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:user,moderator,admin,super_admin'],
        ]);

        $created = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
        ]);

        $audit->write('admin.user_create', (int) $request->user()->id, 'User', (int) $created->id, [
            'role' => $created->role,
        ], $request);

        return back()->with('success', "Akun {$created->email} ({$created->role}) berhasil dibuat.");
    }

    public function toggleBanUser(Request $request, int $userId, AuditWriter $audit): RedirectResponse
    {
        $target = User::query()->findOrFail($userId);

        if ((int) $target->id === (int) $request->user()->id) {
            return back()->with('error', 'Anda tidak dapat mem-ban akun sendiri.');
        }

        $target->banned_at = $target->banned_at === null ? now() : null;
        $target->save();

        $audit->write('admin.user_ban', (int) $request->user()->id, 'User', (int) $target->id, [
            'banned' => $target->banned_at !== null,
        ], $request);

        return back()->with('success', "Status akun {$target->email} berhasil diperbarui.");
    }

    public function runMaintenance(Request $request): RedirectResponse
    {
        $command = (string) $request->input('command', 'probe:upstream');
        if (!in_array($command, ['probe:upstream', 'prune'], true)) {
            return back()->with('error', 'Command tidak diizinkan.');
        }

        $params = $command === 'prune' ? ['--dry-run' => true] : [];
        Artisan::call($command, $params);
        $out = trim(Artisan::output());

        return back()->with('success', "Output {$command}: " . Str::limit($out, 220));
    }
}
