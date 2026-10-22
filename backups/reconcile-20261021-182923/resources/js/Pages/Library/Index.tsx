import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type WatchlistEntry = {
  id: number;
  status: string;
  updated_at: string | null;
  anime: {
    id: number;
    slug: string;
    title: string;
    type: string | null;
    status: string | null;
    year: number | null;
    rating_avg: number | string | null;
    episodes_total: number | null;
  };
};

type HistoryEntry = {
  id: number;
  position_sec: number;
  duration_sec: number | null;
  completed: boolean;
  updated_at: string | null;
  episode: {
    id: number;
    number: number;
    title: string;
  };
  anime: {
    id: number;
    slug: string;
    title: string;
  };
};

type RatingEntry = {
  id: number;
  score: number;
  review_text: string | null;
  updated_at: string | null;
  anime: {
    id: number;
    slug: string;
    title: string;
    year: number | null;
  };
};

type Props = {
  watchlists: WatchlistEntry[];
  history: HistoryEntry[];
  ratings: RatingEntry[];
};

const fmtSec = (sec: number): string => {
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return `${m}m ${s.toString().padStart(2, '0')}s`;
};

export default function LibraryIndex({ watchlists, history, ratings }: Props) {
  const [tab, setTab] = useState<'history' | 'watchlist' | 'ratings'>('history');

  const removeWatchlist = async (animeId: number) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    await fetch(`/api/watchlist/${animeId}`, {
      method: 'DELETE',
      headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
    });
    router.reload();
  };

  return (
    <>
      <Head title="Koleksi Saya" />

      <main className="mx-auto max-w-7xl px-4 py-6">
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-white/10 bg-white/5 p-4">
          <div>
            <h1 className="text-xl font-black text-white">Koleksi Saya</h1>
            <p className="text-xs text-slate-400">
              Lanjut nonton, daftar Koleksi, dan ulasan rating pribadi Anda
            </p>
          </div>

          <div className="flex gap-2">
            {[
              { id: 'history', label: `Riwayat Tonton (${history.length})` },
              { id: 'watchlist', label: `Koleksi (${watchlists.length})` },
              { id: 'ratings', label: `Rating Saya (${ratings.length})` },
            ].map((t) => (
              <button
                key={t.id}
                type="button"
                onClick={() => setTab(t.id as any)}
                className={`rounded-lg px-3.5 py-2 text-xs font-bold transition ${
                  tab === t.id
                    ? 'bg-sky-500 text-slate-950'
                    : 'border border-white/10 bg-slate-900 text-slate-300 hover:bg-white/10'
                }`}
              >
                {t.label}
              </button>
            ))}
          </div>
        </div>

        {tab === 'history' && (
          <section className="space-y-3">
            {history.length === 0 ? (
              <div className="rounded-xl border border-white/10 bg-white/5 p-8 text-center text-sm text-slate-400">
                Belum ada riwayat tontonan.
              </div>
            ) : (
              history.map((h) => {
                const pct =
                  h.duration_sec && h.duration_sec > 0
                    ? Math.min(100, Math.round((h.position_sec / h.duration_sec) * 100))
                    : 100;
                return (
                  <div
                    key={h.id}
                    className="flex flex-col justify-between gap-3 rounded-xl border border-white/10 bg-white/5 p-4 sm:flex-row sm:items-center"
                  >
                    <div className="min-w-0 flex-1">
                      <Link
                        href={`/anime/${h.anime.slug}`}
                        className="text-sm font-bold text-white hover:text-sky-300"
                      >
                        {h.anime.title}
                      </Link>
                      <div className="mt-1 text-xs text-slate-400">
                        {h.episode.title} · Posisi {fmtSec(h.position_sec)} ({pct}%) · {h.updated_at}
                      </div>
                      <div className="mt-2 h-1.5 w-full max-w-md overflow-hidden rounded-full bg-slate-800">
                        <div className="h-full bg-sky-400" style={{ width: `${pct}%` }} />
                      </div>
                    </div>

                    <Link
                      href={`/watch/${h.episode.id}`}
                      className="inline-flex h-9 items-center justify-center rounded-lg bg-sky-500 px-4 text-xs font-black text-slate-950 hover:bg-sky-400"
                    >
                      Lanjut Putar
                    </Link>
                  </div>
                );
              })
            )}
          </section>
        )}

        {tab === 'watchlist' && (
          <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {watchlists.length === 0 ? (
              <div className="col-span-full rounded-xl border border-white/10 bg-white/5 p-8 text-center text-sm text-slate-400">
                Watchlist masih kosong.
              </div>
            ) : (
              watchlists.map((w) => (
                <div
                  key={w.id}
                  className="flex flex-col justify-between rounded-xl border border-white/10 bg-white/5 p-4"
                >
                  <div>
                    <span className="rounded bg-sky-500/20 px-2 py-0.5 text-[10px] font-bold uppercase text-sky-300">
                      {w.status.replace(/_/g, ' ')}
                    </span>
                    <Link
                      href={`/anime/${w.anime.slug}`}
                      className="mt-2 block font-bold text-white hover:text-sky-300"
                    >
                      {w.anime.title}
                    </Link>
                    <div className="mt-1 text-xs text-slate-400">
                      {w.anime.year ?? '-'} · {w.anime.episodes_total ?? 0} Episode
                    </div>
                  </div>

                  <div className="mt-4 flex justify-between gap-2">
                    <Link
                      href={`/anime/${w.anime.slug}`}
                      className="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/15"
                    >
                      Buka Detail
                    </Link>
                    <button
                      type="button"
                      onClick={() => removeWatchlist(w.anime.id)}
                      className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-1.5 text-xs font-semibold text-rose-200 hover:bg-rose-500/20"
                    >
                      Hapus
                    </button>
                  </div>
                </div>
              ))
            )}
          </section>
        )}

        {tab === 'ratings' && (
          <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {ratings.length === 0 ? (
              <div className="col-span-full rounded-xl border border-white/10 bg-white/5 p-8 text-center text-sm text-slate-400">
                Belum ada rating yang Anda berikan.
              </div>
            ) : (
              ratings.map((r) => (
                <div key={r.id} className="rounded-xl border border-white/10 bg-white/5 p-4">
                  <div className="flex items-center justify-between">
                    <Link
                      href={`/anime/${r.anime.slug}`}
                      className="font-bold text-white hover:text-sky-300"
                    >
                      {r.anime.title}
                    </Link>
                    <span className="rounded-md bg-amber-500/20 px-2 py-1 text-xs font-black text-amber-300">
                      ★ {r.score}/10
                    </span>
                  </div>
                  {r.review_text && <p className="mt-2 text-xs text-slate-300">{r.review_text}</p>}
                </div>
              ))
            )}
          </section>
        )}
      </main>
    </>
  );
}
