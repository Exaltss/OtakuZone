import { Head, Link } from '@inertiajs/react';

type Global = {
  anime_total: number;
  anime_ongoing: number;
  anime_completed: number;
  anime_no_year: number;
  anime_no_cat_id: number;
  episode_total: number;
  episode_with_stream: number;
  episode_without_stream: number;
  anime_with_episodes: number;
  anime_without_episodes: number;
  max_episode_number: number;
  episodes_gt_999: number;
  avg_episodes_per_active_anime: number;
  stream_integrity: string;
};

type SyncState = {
  job_key: string;
  last_page_ok: number;
  cursor: string;
  last_status: string;
  last_run_at: string;
  mode: string;
  identity: string;
  total_cids: number | string | null;
  items: number;
  ep_new: number;
  ep_existing: number;
  skipped: number;
  empty_details: number;
  detail_errors: number;
  error: string | null;
};

type ReportHost = {
  host: string;
  whatbox: boolean;
  episodes: number;
  urls: number;
  top_keys: Record<string, number>;
};

type FileInfo = {
  path: string;
  exists: boolean;
  size: number;
  updated_at: string | null;
};

type Props = {
  global: Global;
  syncStates: SyncState[];
  reportStats: Record<string, number | string>;
  reportSummary: Record<string, number | string>;
  reportHosts: ReportHost[];
  files: Record<string, FileInfo>;
  missingCids: number[];
};

const nf = new Intl.NumberFormat('id-ID');

const fmt = (value: number | string | null | undefined): string => {
  if (value === null || value === undefined || value === '') {
    return '-';
  }

  const numeric = Number(value);

  if (Number.isNaN(numeric)) {
    return String(value);
  }

  return nf.format(numeric);
};

const card = (label: string, value: number | string, tone = 'slate'): string => {
  const tones: Record<string, string> = {
    sky: 'border-sky-400/20 bg-sky-500/10 text-sky-100',
    emerald: 'border-emerald-400/20 bg-emerald-500/10 text-emerald-100',
    amber: 'border-amber-400/20 bg-amber-500/10 text-amber-100',
    rose: 'border-rose-400/20 bg-rose-500/10 text-rose-100',
    violet: 'border-violet-400/20 bg-violet-500/10 text-violet-100',
    slate: 'border-white/10 bg-white/5 text-slate-100',
  };

  return `rounded-xl border p-4 ${tones[tone] ?? tones.slate}`;
};

export default function Dashboard({ global, syncStates, reportStats, reportSummary, reportHosts, files, missingCids }: Props) {
  return (
    <>
      <Head title="QA Dashboard" />

      <div className="min-h-screen">
        <header className="border-b border-white/10 bg-slate-950/85">
          <div className="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-5 md:flex-row md:items-center md:justify-between">
            <div>
              <h1 className="text-2xl font-black tracking-tight text-white">Zonaotaku QA Dashboard</h1>
              <p className="mt-1 text-xs text-slate-400">
                Lokal-only · metadata dan health data · tidak menampilkan URL stream
              </p>
            </div>

            <Link
              href="/"
              className="inline-flex h-10 items-center justify-center rounded-lg border border-white/10 bg-white/5 px-4 text-sm font-semibold text-slate-100 transition hover:bg-white/10"
            >
              ← Katalog
            </Link>
          </div>
        </header>

        <main className="mx-auto max-w-7xl space-y-6 px-4 py-6">
          <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div className={card('Anime Total', fmt(global.anime_total), 'sky')}>
              <div className="text-xs uppercase tracking-wide opacity-75">Anime Total</div>
              <div className="mt-2 text-2xl font-black tabular-nums">{fmt(global.anime_total)}</div>
            </div>

            <div className={card('Episode Total', fmt(global.episode_total), 'violet')}>
              <div className="text-xs uppercase tracking-wide opacity-75">Episode Total</div>
              <div className="mt-2 text-2xl font-black tabular-nums">{fmt(global.episode_total)}</div>
            </div>

            <div className={card('Episode Dengan Stream', fmt(global.episode_with_stream), 'emerald')}>
              <div className="text-xs uppercase tracking-wide opacity-75">Episode Dengan Stream</div>
              <div className="mt-2 text-2xl font-black tabular-nums">{fmt(global.episode_with_stream)}</div>
            </div>

            <div className={card('Stream Integrity', global.stream_integrity, global.stream_integrity === 'OK' ? 'emerald' : 'rose')}>
              <div className="text-xs uppercase tracking-wide opacity-75">Stream Integrity</div>
              <div className="mt-2 text-2xl font-black tabular-nums">{global.stream_integrity}</div>
            </div>
          </section>

          <section className="grid gap-4 lg:grid-cols-2">
            <div className="rounded-xl border border-white/10 bg-white/5 p-4">
              <h2 className="text-sm font-bold text-white">Katalog Health</h2>
              <dl className="mt-3 grid grid-cols-2 gap-3 text-xs">
                {[
                  ['Ongoing', global.anime_ongoing],
                  ['Completed', global.anime_completed],
                  ['Tanpa Tahun', global.anime_no_year],
                  ['Tanpa Cat ID', global.anime_no_cat_id],
                  ['Seri Punya Episode', global.anime_with_episodes],
                  ['Seri Tanpa Episode', global.anime_without_episodes],
                  ['Max Episode Number', global.max_episode_number],
                  ['Episode > 999', global.episodes_gt_999],
                  ['Avg Ep / Seri Aktif', global.avg_episodes_per_active_anime],
                  ['Episode Tanpa Stream', global.episode_without_stream],
                ].map(([label, value]) => (
                  <div key={String(label)} className="flex justify-between gap-3 border-b border-white/5 pb-2">
                    <dt className="text-slate-400">{String(label)}</dt>
                    <dd className="font-semibold tabular-nums text-slate-100">{fmt(value as number)}</dd>
                  </div>
                ))}
              </dl>
            </div>

            <div className="rounded-xl border border-white/10 bg-white/5 p-4">
              <h2 className="text-sm font-bold text-white">Host Probe Summary</h2>
              <dl className="mt-3 grid grid-cols-2 gap-3 text-xs">
                {[
                  ['Distinct Hosts', reportSummary.distinct_hosts ?? reportHosts.length],
                  ['Probed Hosts', reportSummary.probed_hosts ?? 0],
                  ['Playable Hosts', reportSummary.playable_hosts ?? 0],
                  ['Failed Hosts', reportSummary.failed_hosts ?? 0],
                  ['Valid URLs', reportStats.valid_urls_total ?? 0],
                  ['Blocked Private/DNS', reportStats.blocked_private_or_dns ?? 0],
                  ['Raw Encrypted', reportStats.raw_looks_encrypted ?? 0],
                  ['Episodes Scanned', reportStats.episodes_scanned ?? 0],
                ].map(([label, value]) => (
                  <div key={String(label)} className="flex justify-between gap-3 border-b border-white/5 pb-2">
                    <dt className="text-slate-400">{String(label)}</dt>
                    <dd className="font-semibold tabular-nums text-slate-100">{fmt(value as number)}</dd>
                  </div>
                ))}
              </dl>
            </div>
          </section>

          <section className="rounded-xl border border-white/10 bg-white/5">
            <div className="border-b border-white/10 px-4 py-3">
              <h2 className="text-sm font-bold text-white">Sync States</h2>
            </div>

            {syncStates.length === 0 ? (
              <div className="px-4 py-8 text-center text-sm text-slate-400">Belum ada sync state.</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-white/10 text-xs">
                  <thead className="bg-white/5 text-slate-300">
                    <tr>
                      {['Job', 'Status', 'Index', 'Run At', 'Mode', 'Identity', 'Items', 'EP New', 'EP Exist', 'Skip', 'Empty', 'Error'].map((h) => (
                        <th key={h} className="px-3 py-2 text-left font-semibold">
                          {h}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-white/5">
                    {syncStates.map((state) => (
                      <tr key={`${state.job_key}-${state.last_run_at}`} className="text-slate-200">
                        <td className="px-3 py-2 font-semibold">{state.job_key}</td>
                        <td className="px-3 py-2">{state.last_status}</td>
                        <td className="px-3 py-2 tabular-nums">{fmt(state.last_page_ok)}</td>
                        <td className="px-3 py-2">{state.last_run_at}</td>
                        <td className="px-3 py-2">{state.mode}</td>
                        <td className="px-3 py-2">{state.identity}</td>
                        <td className="px-3 py-2 tabular-nums">{fmt(state.items)}</td>
                        <td className="px-3 py-2 tabular-nums">{fmt(state.ep_new)}</td>
                        <td className="px-3 py-2 tabular-nums">{fmt(state.ep_existing)}</td>
                        <td className="px-3 py-2 tabular-nums">{fmt(state.skipped)}</td>
                        <td className="px-3 py-2 tabular-nums">{fmt(state.empty_details)}</td>
                        <td className="px-3 py-2">{state.error ?? '-'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          <section className="rounded-xl border border-white/10 bg-white/5">
            <div className="border-b border-white/10 px-4 py-3">
              <h2 className="text-sm font-bold text-white">Host Inventory Sanitized</h2>
              <p className="mt-1 text-xs text-slate-400">
                Host non-whatbox tetap dihitung sebagai asset. URL penuh tidak ditampilkan.
              </p>
            </div>

            {reportHosts.length === 0 ? (
              <div className="px-4 py-8 text-center text-sm text-slate-400">
                Belum ada report host probe. Jalankan probe semua host stream.
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-white/10 text-xs">
                  <thead className="bg-white/5 text-slate-300">
                    <tr>
                      {['Host', 'Whatbox', 'Episodes', 'URLs', 'Top Keys'].map((h) => (
                        <th key={h} className="px-3 py-2 text-left font-semibold">
                          {h}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-white/5">
                    {reportHosts.map((host) => (
                      <tr key={host.host} className="text-slate-200">
                        <td className="px-3 py-2 font-semibold">{host.host}</td>
                        <td className="px-3 py-2">{host.whatbox ? 'yes' : 'no'}</td>
                        <td className="px-3 py-2 tabular-nums">{fmt(host.episodes)}</td>
                        <td className="px-3 py-2 tabular-nums">{fmt(host.urls)}</td>
                        <td className="px-3 py-2">
                          {Object.entries(host.top_keys ?? {})
                            .slice(0, 6)
                            .map(([key, count]) => `${key}:${fmt(count)}`)
                            .join(', ') || '-'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          <section className="grid gap-4 lg:grid-cols-2">
            <div className="rounded-xl border border-white/10 bg-white/5 p-4">
              <h2 className="text-sm font-bold text-white">State Files</h2>
              <dl className="mt-3 space-y-2 text-xs">
                {Object.entries(files).map(([label, file]) => (
                  <div key={label} className="flex justify-between gap-3 border-b border-white/5 pb-2">
                    <dt className="text-slate-400">{label}</dt>
                    <dd className="text-right font-medium text-slate-200">
                      {file.exists ? `${fmt(file.size)} B · ${file.updated_at ?? '-'}` : 'missing'}
                    </dd>
                  </div>
                ))}
              </dl>
            </div>

            <div className="rounded-xl border border-white/10 bg-white/5 p-4">
              <h2 className="text-sm font-bold text-white">Missing CIDs</h2>
              <p className="mt-2 text-xs text-slate-400">
                Total: {fmt(missingCids.length)}
              </p>
              <div className="mt-3 max-h-48 overflow-auto rounded-lg border border-white/10 bg-slate-950/60 p-3 text-xs text-slate-300">
                {missingCids.length === 0 ? '-' : missingCids.slice(0, 200).join(', ')}
              </div>
            </div>
          </section>
        </main>
      </div>
    </>
  );
}
