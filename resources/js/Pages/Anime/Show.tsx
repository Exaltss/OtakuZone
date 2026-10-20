import { Head, Link } from '@inertiajs/react';

type Anime = {
  id: number;
  slug: string;
  title: string;
  type: string | null;
  status: string | null;
  year: number | null;
  rating_avg: number | string | null;
  view_count: number | null;
  cat_id: number | null;
  synopsis: string | null;
  episodes_total: number;
};

type Episode = {
  id: number;
  number: number;
  title: string;
  duration_sec: number | null;
};

type Stats = {
  episode_count: number;
  max_number: number | null;
};

type Props = {
  anime: Anime;
  episodes: Episode[];
  stats: Stats;
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

const duration = (seconds: number | null): string => {
  if (!seconds || seconds <= 0) {
    return '-';
  }

  const m = Math.floor(seconds / 60);
  const s = seconds % 60;

  return `${m}m ${s.toString().padStart(2, '0')}s`;
};

const hasEpisodeMarker = (title: string): boolean => {
  return /\b(?:eps?|episode|ep)\.?\s*#?\s*\d+/iu.test(title);
};

const episodeHundredths = (value: number): number => {
  return Math.round(value * 100);
};

const formatPlainNumber = (value: number): string => {
  const hundredths = episodeHundredths(value);
  const base = Math.floor(hundredths / 100);
  const frac = hundredths % 100;

  if (frac === 0) {
    return String(base);
  }

  return `${base},${frac.toString().padStart(2, '0')}`;
};

const episodeLabel = (episode: Episode): string => {
  const title = episode.title ?? '';
  const hundredths = episodeHundredths(Number(episode.number || 0));
  const base = Math.floor(hundredths / 100);
  const frac = hundredths % 100;

  if (!hasEpisodeMarker(title)) {
    return title || `Episode ${formatPlainNumber(Number(episode.number || 0))}`;
  }

  if (frac === 0) {
    return `Episode ${base}`;
  }

  return `Episode ${base} (varian ${frac})`;
};

export default function Show({ anime, episodes, stats }: Props) {
  return (
    <>
      <Head title={anime.title} />

      <div className="min-h-screen">
        <header className="border-b border-white/10 bg-slate-950/85">
          <div className="mx-auto max-w-7xl px-4 py-5">
            <Link href="/" className="text-xs font-semibold text-sky-300 hover:text-sky-200">
              ← Kembali ke katalog
            </Link>

            <div className="mt-3 flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
              <div>
                <h1 className="text-3xl font-black tracking-tight text-white">{anime.title}</h1>

                <div className="mt-3 flex flex-wrap gap-2 text-xs">
                  <span className="rounded-md bg-sky-500/15 px-2 py-1 text-sky-200 ring-1 ring-inset ring-sky-400/20">
                    {anime.type ?? 'type?'}
                  </span>
                  <span className="rounded-md bg-emerald-500/15 px-2 py-1 text-emerald-200 ring-1 ring-inset ring-emerald-400/20">
                    {anime.status ?? 'status?'}
                  </span>
                  <span className="rounded-md bg-amber-500/15 px-2 py-1 text-amber-200 ring-1 ring-inset ring-amber-400/20">
                    {anime.year ?? 'year?'}
                  </span>
                  <span className="rounded-md bg-slate-500/15 px-2 py-1 text-slate-200 ring-1 ring-inset ring-slate-400/20">
                    cat_id {fmt(anime.cat_id)}
                  </span>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3 text-right md:grid-cols-4">
                {[
                  { label: 'DB Episodes', value: fmt(stats.episode_count) },
                  { label: 'Episodes Total', value: fmt(anime.episodes_total) },
                  { label: 'Max Number', value: fmt(stats.max_number) },
                  { label: 'Views', value: fmt(anime.view_count) },
                ].map((card) => (
                  <div key={card.label} className="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                    <div className="text-[10px] uppercase tracking-wide text-slate-400">{card.label}</div>
                    <div className="mt-1 text-sm font-bold tabular-nums text-white">{card.value}</div>
                  </div>
                ))}
              </div>
            </div>

            {anime.synopsis ? (
              <p className="mt-5 max-w-4xl text-sm leading-relaxed text-slate-300">
                {anime.synopsis}
              </p>
            ) : null}
          </div>
        </header>

        <main className="mx-auto max-w-7xl px-4 py-6">
          <section className="rounded-xl border border-white/10 bg-white/5">
            <div className="border-b border-white/10 px-4 py-3">
              <h2 className="text-sm font-bold text-white">Daftar Episode</h2>
              <p className="mt-1 text-xs text-slate-400">
                Urutan memakai nomor episode asli. Duplikat diberi varian .01, .02, dst.
              </p>
            </div>

            {episodes.length === 0 ? (
              <div className="px-4 py-10 text-center text-sm text-slate-400">
                Belum ada episode tersimpan untuk anime ini.
              </div>
            ) : (
              <div className="divide-y divide-white/5">
                {episodes.map((episode) => (
                  <Link
                    key={episode.id}
                    href={`/watch/${episode.id}`}
                    className="flex items-center justify-between gap-4 px-4 py-3 transition hover:bg-white/5"
                  >
                    <div className="min-w-0">
                      <div className="truncate text-sm font-semibold text-white">
                        {episodeLabel(episode)}
                      </div>
                      <div className="mt-1 truncate text-xs text-slate-400">
                        {episode.title} · episode_id {fmt(episode.id)} · durasi {duration(episode.duration_sec)}
                      </div>
                    </div>

                    <span className="shrink-0 rounded-lg bg-sky-500 px-3 py-1.5 text-xs font-bold text-slate-950">
                      Putar
                    </span>
                  </Link>
                ))}
              </div>
            )}
          </section>
        </main>
      </div>
    </>
  );
}
