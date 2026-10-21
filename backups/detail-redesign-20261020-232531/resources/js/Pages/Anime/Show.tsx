import { Head, Link } from '@inertiajs/react';
import { ReactNode, useMemo, useRef, useState } from 'react';
import MainLayout from '@/Layouts/MainLayout';
import { Poster, fmtEpisode } from '@/Components/AnimeCard';

type Episode = {
  id: number;
  number: number;
  title: string;
  duration_sec: number | null;
};

type MetaRow = {
  label: string;
  value: string;
};

type Props = {
  anime: {
    id: number;
    slug: string;
    title: string;
    type: string | null;
    status: string | null;
    year: number | null;
    season: string | null;
    rating_avg: string | number | null;
    view_count: number | null;
    cat_id: number | null;
    synopsis: string | null;
    genres: string[];
    meta: MetaRow[];
    episodes_total: number;
    image_path: string | null;
  };
  episodes: Episode[];
  stats: {
    episode_count: number;
    max_number: number | null;
  };
  initialQualities: string[];
  signMode: 'local' | 'guest';
};

const STANDARD_CHAIN = ['1080', '720', '480', '360'];

const nf = new Intl.NumberFormat('id-ID');

const fmt = (value: number | string | null | undefined): string => {
  if (value === null || value === undefined || value === '') return '-';
  const numeric = Number(value);
  return Number.isNaN(numeric) ? String(value) : nf.format(numeric);
};

const duration = (seconds: number | null): string => {
  if (!seconds || seconds <= 0) return '-';
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${m}m ${s.toString().padStart(2, '0')}s`;
};

const qualityLabel = (quality: string): string => {
  const q = quality.toLowerCase();

  if (q === '1080' || q === '720' || q === '480' || q === '360') return `${quality}p`;
  if (q === 'eng') return 'Eng Dub';
  if (q === 'hdeng') return 'HD Eng Dub';
  if (q === 'auto') return 'Auto';

  return quality;
};

const PlayIcon = ({ className = 'h-4 w-4' }: { className?: string }) => (
  <svg viewBox="0 0 24 24" fill="currentColor" className={className}>
    <path d="M8 5.5v13l11-6.5-11-6.5Z" />
  </svg>
);

const RefreshIcon = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-3.5 w-3.5">
    <path
      d="M21 12a9 9 0 1 1-2.64-6.36M21 3v6h-6"
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </svg>
);

const SearchIcon = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-4 w-4">
    <circle cx="11" cy="11" r="7" />
    <path d="m20 20-3.5-3.5" strokeLinecap="round" />
  </svg>
);

const SortIcon = ({ asc }: { asc: boolean }) => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" className="h-3.5 w-3.5">
    {asc ? (
      <path d="M12 19V5m-6 6 6-6 6 6" strokeLinecap="round" strokeLinejoin="round" />
    ) : (
      <path d="M12 5v14m6-6-6 6-6-6" strokeLinecap="round" strokeLinejoin="round" />
    )}
  </svg>
);

const Eyebrow = ({ children }: { children: ReactNode }) => (
  <div className="flex items-center gap-3 text-[11px] font-bold uppercase tracking-[0.3em] text-accent">
    <span className="h-px w-6 bg-accent" />
    {children}
  </div>
);

export default function Show({ anime, episodes, stats, initialQualities, signMode }: Props) {
  const ongoing = (anime.status ?? '').toLowerCase() === 'ongoing';
  const first = episodes[0] ?? null;

  const [episodeId, setEpisodeId] = useState<number | null>(first?.id ?? null);
  const [quality, setQuality] = useState<string>(
    initialQualities.includes('720') ? '720' : (initialQualities[0] ?? '720')
  );
  const [qualitiesCache, setQualitiesCache] = useState<Record<number, string[]>>(() =>
    first ? { [first.id]: initialQualities } : {}
  );
  const [streamUrl, setStreamUrl] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [hasPlayed, setHasPlayed] = useState(false);
  const [search, setSearch] = useState('');
  const [sortAsc, setSortAsc] = useState(true);
  const playerRef = useRef<HTMLDivElement | null>(null);

  const current = episodes.find((item) => item.id === episodeId) ?? first;
  const qualities = useMemo(() => {
    if (current && (qualitiesCache[current.id]?.length ?? 0) > 0) return qualitiesCache[current.id];
    if (initialQualities.length > 0) return initialQualities;
    return STANDARD_CHAIN;
  }, [current, qualitiesCache, initialQualities]);

  const ordered = useMemo(() => {
    const list = [...episodes];
    list.sort((a, b) =>
      sortAsc
        ? Number(a.number) - Number(b.number) || a.id - b.id
        : Number(b.number) - Number(a.number) || b.id - a.id
    );
    return list;
  }, [episodes, sortAsc]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (q === '') return ordered;
    return ordered.filter(
      (item) =>
        fmtEpisode(Number(item.number)).toLowerCase().includes(q) ||
        (item.title ?? '').toLowerCase().includes(q)
    );
  }, [ordered, search]);

  const play = async (targetEpisodeId: number, targetQuality: string) => {
    setLoading(true);
    setError(null);
    setStreamUrl(null);

    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
      const headers = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
      };

      let nonce: string | undefined;

      if (signMode === 'guest') {
        const nres = await fetch('/api/guest/stream/nonce', {
          method: 'POST',
          headers,
          body: JSON.stringify({}),
        });
        const ndata = await nres.json();
        if (!nres.ok) throw new Error(ndata?.error?.message ?? `HTTP ${nres.status}`);
        nonce = ndata.nonce;
      }

      const response = await fetch(
        signMode === 'guest' ? '/api/guest/stream/sign' : '/api/local/stream/sign',
        {
          method: 'POST',
          headers,
          body: JSON.stringify({
            episode_id: Number(targetEpisodeId),
            quality: targetQuality,
            ...(nonce ? { nonce } : {}),
          }),
        }
      );

      const data = await response.json();
      if (!response.ok) throw new Error(data?.error?.message ?? `HTTP ${response.status}`);

      setStreamUrl(data.stream_url);
      setQualitiesCache((prev) => ({ ...prev, [targetEpisodeId]: data.qualities }));
      setQuality(data.default_quality);
      setHasPlayed(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal membuat token stream.');
    } finally {
      setLoading(false);
    }
  };

  const selectEpisode = (id: number) => {
    setEpisodeId(id);
    void play(id, quality);
  };

  const changeQuality = (next: string) => {
    setQuality(next);
    if (hasPlayed && current) void play(current.id, next);
  };

  const refresh = () => {
    if (current) void play(current.id, quality);
  };

  const onEnded = () => {
    if (!current) return;
    const index = episodes.findIndex((item) => item.id === current.id);
    const next = episodes[index + 1];
    if (next) selectEpisode(next.id);
  };

  return (
    <MainLayout>
      <Head title={`${anime.title} · Zonaotaku`} />

      {/* ============ HERO ============ */}
      <section className="relative overflow-hidden border-b border-white/5">
        <div className="absolute inset-0">
          <Poster src={anime.image_path} title={anime.title} className="scale-105 blur-[6px]" />
          <div className="absolute inset-0 bg-gradient-to-r from-night-950 via-night-950/92 to-night-900/55" />
          <div className="absolute inset-0 bg-gradient-to-t from-night-900 via-transparent to-night-950/70" />
        </div>

        <div className="relative mx-auto max-w-7xl px-4 py-10 md:px-6 md:py-14">
          <nav className="flex flex-wrap items-center gap-2 text-xs text-slate-400">
            <Link href="/" className="transition hover:text-white">
              Beranda
            </Link>
            <span className="text-slate-600">/</span>
            <Link href="/?sort=title" className="transition hover:text-white">
              Anime
            </Link>
            <span className="text-slate-600">/</span>
            <span className="font-semibold text-slate-200">{anime.title}</span>
          </nav>

          <div className="mt-6 flex flex-col gap-8 md:flex-row md:items-start">
            <div className="w-40 shrink-0 md:w-48">
              <div className="overflow-hidden rounded-xl border border-white/10 shadow-card">
                <Poster src={anime.image_path} title={anime.title} className="aspect-[2/3] w-full" />
              </div>
            </div>

            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2 text-[11px] font-bold uppercase tracking-wider">
                <span className="rounded bg-white/10 px-2 py-1 text-slate-100 ring-1 ring-inset ring-white/10">
                  {((anime.type ?? '') + '').toLowerCase() === 'movie' ? 'Movie' : 'TV Series'}
                </span>
                <span
                  className={`rounded px-2 py-1 ring-1 ring-inset ${
                    ongoing
                      ? 'bg-sky-500/15 text-sky-200 ring-sky-400/25'
                      : 'bg-emerald-500/15 text-emerald-200 ring-emerald-400/25'
                  }`}
                >
                  {anime.status ?? 'Unknown'}
                </span>
              </div>

              <h1 className="mt-3 text-3xl font-extrabold tracking-tight text-white md:text-5xl">
                {anime.title}
                <span className="text-accent">.</span>
              </h1>

              <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-300">
                <span>{anime.year ?? '????'}</span>
                <span className="text-slate-600">•</span>
                <span>
                  {duration(current?.duration_sec ?? null) !== '-'
                    ? `${duration(current?.duration_sec ?? null)} / ep`
                    : 'Durasi -'}
                </span>
                <span className="text-slate-600">•</span>
                <span className="font-semibold text-slate-200">Sub Indo</span>
              </div>

              {anime.genres.length > 0 ? (
                <div className="mt-4 flex flex-wrap gap-2">
                  {anime.genres.map((genre) => (
                    <span
                      key={genre}
                      className="rounded-full border border-white/10 bg-white/[0.04] px-3 py-1 text-xs font-medium text-slate-200 transition hover:border-accent/40 hover:text-accent-soft"
                    >
                      {genre}
                    </span>
                  ))}
                </div>
              ) : null}

              <div className="mt-5 flex flex-wrap items-center gap-6">
                <div>
                  <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                    Rating anime
                  </div>
                  <div className="mt-1 flex items-baseline gap-2">
                    <span className="text-2xl font-extrabold text-white">
                      {anime.rating_avg === null ? '-' : Number(anime.rating_avg).toFixed(1)}
                      <span className="text-sm font-semibold text-slate-400">/10</span>
                    </span>
                    <span className="text-xs text-slate-500">{fmt(anime.view_count)}x ditonton</span>
                  </div>
                </div>
              </div>

              <div className="mt-6 flex flex-wrap items-center gap-3">
                {first ? (
                  <button
                    type="button"
                    onClick={() => {
                      setEpisodeId(first.id);
                      void play(first.id, quality);
                      playerRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }}
                    className="inline-flex items-center gap-2 rounded-lg bg-accent px-6 py-3 text-sm font-bold text-night-950 transition hover:bg-accent-soft"
                  >
                    <PlayIcon />
                    Mulai nonton
                  </button>
                ) : null}
                <span className="text-sm text-slate-400">
                  <span className="font-semibold text-slate-200">{fmt(stats.episode_count)}</span> episode
                  tersimpan
                  {stats.max_number !== null ? ` · terakhir ep ${fmtEpisode(Number(stats.max_number))}` : ''}
                </span>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ============ PLAYER + SIDEBAR ============ */}
      <div className="mx-auto max-w-7xl px-4 py-8 md:px-6">
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
          {/* ---- kiri: player ---- */}
          <div className="min-w-0" ref={playerRef}>
            <section className="overflow-hidden rounded-2xl border border-white/5 bg-black shadow-card">
              <div className="flex flex-wrap items-center justify-between gap-3 border-b border-white/5 bg-night-850 px-4 py-3">
                <div className="min-w-0">
                  <div className="truncate text-sm font-bold text-white">
                    {anime.title}{' '}
                    {current ? `Episode ${fmtEpisode(Number(current.number))}` : ''}{' '}
                    <span className="font-medium text-slate-400">Subtitle Indonesia</span>
                  </div>
                  <div className="mt-0.5 truncate text-xs text-slate-500">
                    {current?.title || 'Tanpa judul'} · {duration(current?.duration_sec ?? null)}
                  </div>
                </div>
                <button
                  type="button"
                  onClick={refresh}
                  disabled={loading || !current}
                  className="inline-flex shrink-0 items-center gap-2 rounded-lg border border-white/10 bg-white/[0.04] px-3 py-2 text-xs font-semibold text-slate-200 transition hover:border-accent/40 hover:text-accent-soft disabled:cursor-not-allowed disabled:opacity-40"
                >
                  <RefreshIcon />
                  {loading ? 'Memuat...' : 'Refresh Player'}
                </button>
              </div>

              {streamUrl ? (
                <video
                  key={streamUrl}
                  src={streamUrl}
                  controls
                  autoPlay
                  playsInline
                  preload="metadata"
                  onEnded={onEnded}
                  className="aspect-video w-full bg-black"
                />
              ) : (
                <button
                  type="button"
                  onClick={() => {
                    if (!current) return;
                    setEpisodeId(current.id);
                    void play(current.id, quality);
                  }}
                  disabled={!current || loading}
                  className="group flex aspect-video w-full flex-col items-center justify-center gap-4 bg-gradient-to-br from-night-800 via-night-900 to-night-950 px-6 text-center transition hover:from-night-700 hover:via-night-800 hover:to-night-900 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  <span className="flex h-16 w-16 items-center justify-center rounded-2xl bg-accent/15 text-accent transition group-hover:scale-105 group-hover:bg-accent group-hover:text-night-950">
                    <PlayIcon className="h-7 w-7" />
                  </span>
                  <p className="max-w-sm text-sm text-slate-400">
                    {!current
                      ? 'Episode belum tersinkron untuk seri ini.'
                      : loading
                        ? 'Membuat token stream...'
                        : `Tekan play untuk memutar Episode ${fmtEpisode(Number(current.number))}.`}
                  </p>
                </button>
              )}
            </section>

            {error ? (
              <div className="mt-4 rounded-lg border border-rose-400/20 bg-rose-500/10 p-3 text-sm text-rose-200">
                {error}
              </div>
            ) : null}

            <p className="mt-3 text-xs leading-relaxed text-slate-500">
              Jika video tidak bisa diputar, coba kualitas lain pada panel Resolusi, atau tekan Refresh
              Player. URL upstream asli tidak pernah dikirim ke browser.
            </p>

            {/* ---- informasi anime ---- */}
            <section className="mt-8 rounded-2xl border border-white/5 bg-white/[0.03] p-5 md:p-6">
              <Eyebrow>Di balik datanya</Eyebrow>
              <h2 className="mt-2 text-xl font-extrabold text-white">Informasi anime</h2>

              <dl className="mt-4 grid grid-cols-1 gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                <div className="flex justify-between gap-4 border-b border-white/5 py-1.5">
                  <dt className="text-slate-400">Judul</dt>
                  <dd className="text-right font-medium text-slate-100">{anime.title}</dd>
                </div>
                <div className="flex justify-between gap-4 border-b border-white/5 py-1.5">
                  <dt className="text-slate-400">Tipe</dt>
                  <dd className="text-right font-medium text-slate-100">{anime.type ?? '-'}</dd>
                </div>
                <div className="flex justify-between gap-4 border-b border-white/5 py-1.5">
                  <dt className="text-slate-400">Status</dt>
                  <dd className="text-right font-medium text-slate-100">{anime.status ?? '-'}</dd>
                </div>
                <div className="flex justify-between gap-4 border-b border-white/5 py-1.5">
                  <dt className="text-slate-400">Tahun</dt>
                  <dd className="text-right font-medium tabular-nums text-slate-100">
                    {anime.year ?? '-'}
                  </dd>
                </div>
                <div className="flex justify-between gap-4 border-b border-white/5 py-1.5">
                  <dt className="text-slate-400">Episode tersimpan</dt>
                  <dd className="text-right font-medium tabular-nums text-slate-100">
                    {fmt(stats.episode_count)}
                    {anime.episodes_total ? ` / ${fmt(anime.episodes_total)}` : ''}
                  </dd>
                </div>
                <div className="flex justify-between gap-4 border-b border-white/5 py-1.5">
                  <dt className="text-slate-400">Skor</dt>
                  <dd className="text-right font-medium tabular-nums text-slate-100">
                    {anime.rating_avg === null ? '-' : `${Number(anime.rating_avg).toFixed(1)}/10`}
                  </dd>
                </div>
                {anime.meta.map((row) => (
                  <div
                    key={row.label}
                    className="flex justify-between gap-4 border-b border-white/5 py-1.5 sm:col-span-2"
                  >
                    <dt className="shrink-0 text-slate-400">{row.label}</dt>
                    <dd className="text-right font-medium text-slate-100">{row.value}</dd>
                  </div>
                ))}
              </dl>
            </section>

            {/* ---- sinopsis ---- */}
            <section className="mt-6 rounded-2xl border border-white/5 bg-white/[0.03] p-5 md:p-6">
              <Eyebrow>Wajah di balik cerita</Eyebrow>
              <h2 className="mt-2 text-xl font-extrabold text-white">Sinopsis</h2>
              {anime.synopsis ? (
                <p className="mt-4 whitespace-pre-line text-sm leading-relaxed text-slate-300">
                  {anime.synopsis}
                </p>
              ) : (
                <p className="mt-4 text-sm text-slate-500">Sinopsis belum tersedia untuk anime ini.</p>
              )}
            </section>
          </div>

          {/* ---- kanan: resolusi (atas) + daftar episode (bawah) ---- */}
          <aside className="flex min-w-0 flex-col gap-6">
            <section className="rounded-2xl border border-white/5 bg-white/[0.03] p-5">
              <Eyebrow>Pilih kualitas</Eyebrow>
              <h2 className="mt-2 text-lg font-extrabold text-white">Resolusi</h2>

              <div className="mt-4 grid grid-cols-2 gap-2">
                {qualities.map((item) => (
                  <button
                    key={item}
                    type="button"
                    onClick={() => changeQuality(item)}
                    className={`rounded-lg px-3 py-2.5 text-sm font-bold transition ${
                      quality === item
                        ? 'bg-accent text-night-950 shadow-lg shadow-accent/20'
                        : 'border border-white/10 bg-night-800 text-slate-200 hover:border-accent/40 hover:text-accent-soft'
                    }`}
                  >
                    {qualityLabel(item)}
                  </button>
                ))}
              </div>

              <p className="mt-3 text-xs text-slate-500">
                Kualitas diterapkan otomatis saat episode diputar.
              </p>
            </section>

            <section className="flex min-h-0 flex-1 flex-col rounded-2xl border border-white/5 bg-white/[0.03]">
              <div className="border-b border-white/5 p-5 pb-4">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <Eyebrow>Arsip</Eyebrow>
                    <h2 className="mt-2 text-lg font-extrabold text-white">Daftar episode</h2>
                  </div>
                  <div className="flex items-center gap-2">
                    <button
                      type="button"
                      onClick={() => setSortAsc((prev) => !prev)}
                      title={sortAsc ? 'Urutan naik (1 → terakhir)' : 'Urutan turun (terakhir → 1)'}
                      aria-label={sortAsc ? 'Urutkan episode menurun' : 'Urutkan episode menaik'}
                      className="inline-flex items-center gap-1.5 rounded-md border border-white/10 bg-night-800 px-2.5 py-1 text-[11px] font-bold tracking-wider text-slate-200 ring-1 ring-inset ring-white/5 transition hover:border-accent/40 hover:text-accent-soft"
                    >
                      <SortIcon asc={sortAsc} />
                      {sortAsc ? 'ASC' : 'DESC'}
                    </button>
                    <span className="rounded-md bg-night-800 px-2.5 py-1 text-xs font-bold tabular-nums text-accent ring-1 ring-inset ring-white/5">
                      {fmt(stats.episode_count)}
                    </span>
                  </div>
                </div>

                <div className="relative mt-4">
                  <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-500">
                    <SearchIcon />
                  </span>
                  <input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Cari episode..."
                    className="h-10 w-full rounded-lg border border-white/10 bg-night-900 pl-9 pr-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-accent/40 focus:ring-2 focus:ring-accent/20"
                  />
                </div>
              </div>

              <div className="max-h-[520px] min-h-[220px] flex-1 overflow-y-auto p-3">
                {filtered.length === 0 ? (
                  <div className="p-6 text-center text-sm text-slate-500">
                    {episodes.length === 0
                      ? 'Episode belum tersinkron untuk seri ini.'
                      : 'Episode tidak ditemukan.'}
                  </div>
                ) : (
                  <ul className="grid grid-cols-6 gap-2 sm:grid-cols-8">
                    {filtered.map((episode) => {
                      const active = current?.id === episode.id;
                      return (
                        <li key={episode.id}>
                          <button
                            type="button"
                            onClick={() => selectEpisode(episode.id)}
                            title={`Putar Episode ${fmtEpisode(Number(episode.number))}`}
                            aria-label={`Putar Episode ${fmtEpisode(Number(episode.number))}`}
                            className={`flex aspect-square w-full items-center justify-center rounded-lg text-sm font-black tabular-nums ring-1 ring-inset transition ${
                              active
                                ? 'bg-accent text-night-950 ring-accent shadow-lg shadow-accent/20'
                                : 'bg-night-800 text-slate-200 ring-white/5 hover:bg-night-700 hover:text-accent-soft hover:ring-accent/30'
                            }`}
                          >
                            {fmtEpisode(Number(episode.number))}
                          </button>
                        </li>
                      );
                    })}
                  </ul>
                )}
              </div>
            </section>
          </aside>
        </div>
      </div>
    </MainLayout>
  );
}
