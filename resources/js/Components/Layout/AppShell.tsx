import { Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type AuthUser = {
  id: number;
  name: string;
  email: string;
  role: string;
  is_admin: boolean;
};

type PageProps = {
  appName?: string;
  auth?: {
    user?: AuthUser | null;
  };
  flash?: {
    success?: string | null;
    error?: string | null;
  };
  anime?: {
    id?: number;
    slug?: string;
    title?: string;
  };
  episode?: {
    id?: number;
    number?: number;
    title?: string;
    duration_sec?: number | null;
  };
};

export default function AppShell({ children }: { children: ReactNode }) {
  const { props, url } = usePage<PageProps>();
  const user = props.auth?.user ?? null;
  const flash = props.flash;
  const currentAnime = props.anime;
  const currentEpisode = props.episode;

  const [wlStatus, setWlStatus] = useState('watching');
  const [ratingScore, setRatingScore] = useState('9');
  const [actionMsg, setActionMsg] = useState<string | null>(null);

  const csrf = (): string =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

  const saveWatchlist = async () => {
    if (!currentAnime?.id) return;
    setActionMsg(null);
    const res = await fetch('/api/watchlist', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf(),
      },
      body: JSON.stringify({ anime_id: currentAnime.id, status: wlStatus }),
    });
    setActionMsg(res.ok ? 'Tersimpan di Watchlist!' : 'Login terlebih dahulu untuk menyimpan Watchlist.');
  };

  const saveRating = async () => {
    if (!currentAnime?.id) return;
    setActionMsg(null);
    const res = await fetch('/api/ratings', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf(),
      },
      body: JSON.stringify({ anime_id: currentAnime.id, score: Number(ratingScore) }),
    });
    setActionMsg(res.ok ? `Rating ${ratingScore}/10 tersimpan!` : 'Login terlebih dahulu untuk memberi rating.');
  };

  const markEpisodeWatched = async () => {
    if (!currentEpisode?.id) return;
    setActionMsg(null);
    const dur = currentEpisode.duration_sec ?? 1440;
    const res = await fetch('/api/progress', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf(),
      },
      body: JSON.stringify({
        episode_id: currentEpisode.id,
        position_sec: dur,
        duration_sec: dur,
      }),
    });
    setActionMsg(res.ok ? 'Riwayat tontonan berhasil disimpan!' : 'Login untuk menyimpan riwayat tontonan.');
  };

  const navItems = [
    { href: '/', label: 'Katalog', match: (u: string) => u === '/' || u.startsWith('/?') },
    { href: '/browse', label: 'Browse A–Z', match: (u: string) => u.startsWith('/browse') },
    { href: '/search', label: 'Pencarian', match: (u: string) => u.startsWith('/search') },
    { href: '/library', label: 'Library Saya', match: (u: string) => u.startsWith('/library') },
  ];

  if (user?.is_admin) {
    navItems.push({
      href: '/admin',
      label: 'Admin CMS',
      match: (u: string) => u.startsWith('/admin'),
    });
  }

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100">
      <nav className="sticky top-0 z-40 border-b border-sky-500/20 bg-slate-950/95 backdrop-blur">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-2.5">
          <div className="flex items-center gap-6">
            <Link href="/" className="flex items-center gap-2 text-base font-black tracking-tight text-white">
              <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-sky-500 text-xs font-black text-slate-950">
                ZO
              </span>
              <span>ZONAOTAKU</span>
            </Link>

            <div className="flex flex-wrap items-center gap-1">
              {navItems.map((item) => {
                const active = item.match(url);
                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                      active
                        ? 'bg-sky-500/20 text-sky-300 ring-1 ring-inset ring-sky-400/30'
                        : 'text-slate-300 hover:bg-white/5 hover:text-white'
                    }`}
                  >
                    {item.label}
                  </Link>
                );
              })}
            </div>
          </div>

          <div className="flex items-center gap-2 text-xs">
            {user ? (
              <>
                <span className="hidden rounded-md border border-white/10 bg-white/5 px-2.5 py-1 text-slate-300 sm:inline-block">
                  {user.name} <span className="text-sky-400">({user.role})</span>
                </span>
                <button
                  type="button"
                  onClick={() => router.post('/logout')}
                  className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-1.5 font-semibold text-rose-200 transition hover:bg-rose-500/20"
                >
                  Keluar
                </button>
              </>
            ) : (
              <Link
                href="/login"
                className="rounded-lg bg-sky-500 px-3.5 py-1.5 font-bold text-slate-950 transition hover:bg-sky-400"
              >
                Masuk
              </Link>
            )}
          </div>
        </div>

        {(flash?.success || flash?.error || actionMsg) && (
          <div className="border-t border-white/10 bg-slate-900/90 px-4 py-2 text-center text-xs font-medium">
            {flash?.success && <span className="text-emerald-300">{flash.success}</span>}
            {flash?.error && <span className="text-rose-300">{flash.error}</span>}
            {actionMsg && <span className="text-sky-300">{actionMsg}</span>}
          </div>
        )}

        {currentAnime?.id && (
          <div className="border-t border-white/5 bg-slate-900/60 px-4 py-2">
            <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 text-xs">
              <div className="flex flex-wrap items-center gap-2 text-slate-300">
                <span className="font-semibold text-white">{currentAnime.title}</span>
                {currentAnime.slug && (
                  <a
                    href={`/og/${currentAnime.slug}`}
                    target="_blank"
                    rel="noreferrer"
                    className="rounded bg-white/5 px-2 py-0.5 text-[11px] text-sky-300 hover:bg-white/10"
                  >
                    Link Share OG
                  </a>
                )}
              </div>

              <div className="flex flex-wrap items-center gap-2">
                <select
                  value={wlStatus}
                  onChange={(e) => setWlStatus(e.target.value)}
                  className="h-7 rounded border border-white/10 bg-slate-950 px-2 text-xs text-slate-200"
                >
                  <option value="watching">Watching</option>
                  <option value="plan_to_watch">Plan to Watch</option>
                  <option value="completed">Completed</option>
                  <option value="on_hold">On Hold</option>
                  <option value="dropped">Dropped</option>
                </select>
                <button
                  type="button"
                  onClick={saveWatchlist}
                  className="h-7 rounded bg-sky-500/20 px-2.5 font-semibold text-sky-200 ring-1 ring-sky-400/30 hover:bg-sky-500/30"
                >
                  + Watchlist
                </button>

                <select
                  value={ratingScore}
                  onChange={(e) => setRatingScore(e.target.value)}
                  className="h-7 rounded border border-white/10 bg-slate-950 px-2 text-xs text-slate-200"
                >
                  {[10, 9, 8, 7, 6, 5, 4, 3, 2, 1].map((s) => (
                    <option key={s} value={s}>
                      ★ {s}/10
                    </option>
                  ))}
                </select>
                <button
                  type="button"
                  onClick={saveRating}
                  className="h-7 rounded bg-amber-500/20 px-2.5 font-semibold text-amber-200 ring-1 ring-amber-400/30 hover:bg-amber-500/30"
                >
                  Beri Rating
                </button>

                {currentEpisode?.id && (
                  <button
                    type="button"
                    onClick={markEpisodeWatched}
                    className="h-7 rounded bg-emerald-500/20 px-2.5 font-semibold text-emerald-200 ring-1 ring-emerald-400/30 hover:bg-emerald-500/30"
                  >
                    ✓ Tandai Selesai Ditonton
                  </button>
                )}
              </div>
            </div>
          </div>
        )}
      </nav>

      <div>{children}</div>
    </div>
  );
}
