import { Head, Link } from '@inertiajs/react';
import MainLayout from '@/Layouts/MainLayout';
import { Poster, SectionHead, fmtEpisode } from '@/Components/AnimeCard';

type Episode = {
  id: number;
  number: number;
  title: string;
  duration_sec: number | null;
};

type Props = {
  anime: {
    id: number;
    slug: string;
    title: string;
    type: string | null;
    status: string | null;
    year: number | null;
    rating_avg: string | number | null;
    view_count: number | null;
    cat_id: number | null;
    synopsis: string | null;
    episodes_total: number;
    image_path: string | null;
  };
  episodes: Episode[];
  stats: {
    episode_count: number;
    max_number: number | null;
  };
};

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

const PlayIcon = () => (
  <svg viewBox="0 0 24 24" fill="currentColor" className="h-4 w-4">
    <path d="M8 5.5v13l11-6.5-11-6.5Z" />
  </svg>
);

export default function Show({ anime, episodes, stats }: Props) {
  const ongoing = (anime.status ?? '').toLowerCase() === 'ongoing';
  const first = episodes[0];

  return (
    <MainLayout>
      <Head title={anime.title} />

      <section className="relative overflow-hidden border-b border-white/5">
        <div className="absolute inset-0">
          <Poster src={anime.image_path} title={anime.title} className="scale-105 blur-[3px]" />
          <div className="absolute inset-0 bg-gradient-to-r from-night-900 via-night-900/90 to-night-900/40" />
          <div className="absolute inset-0 bg-gradient-to-t from-night-900 via-transparent to-night-900/60" />
        </div>

        <div className="relative mx-auto max-w-7xl px-4 py-14 md:px-6 md:py-20">
          <Link
            href="/"
            className="inline-flex items-center gap-2 text-xs font-semibold text-sky-300 transition hover:text-sky-200"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-3.5 w-3.5">
              <path d="M19 12H5m6 6-6-6 6-6" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
            Kembali ke katalog
          </Link>

          <div className="mt-6 flex items-center gap-3 text-[11px] font-bold uppercase tracking-[0.3em] text-accent">
            <span className="h-px w-6 bg-accent" />
            Detail anime
          </div>

          <h1 className="mt-4 max-w-3xl text-4xl font-extrabold tracking-tight text-white md:text-5xl">
            {anime.title}
            <span className="text-sky-400">.</span>
          </h1>

          <div className="mt-5 flex flex-wrap items-center gap-2 text-sm text-slate-300">
            <span className="rounded-md bg-white/5 px-2.5 py-1 ring-1 ring-inset ring-white/10">
              {((anime.type ?? '') + '').toLowerCase() === 'movie' ? 'Movie' : 'TV Series'}
            </span>
            <span className="rounded-md bg-white/5 px-2.5 py-1 ring-1 ring-inset ring-white/10">{anime.year ?? '????'}</span>
            <span
              className={`rounded-md px-2.5 py-1 ring-1 ring-inset ${
                ongoing
                  ? 'bg-sky-500/10 text-sky-200 ring-sky-400/20'
                  : 'bg-emerald-500/10 text-emerald-200 ring-emerald-400/20'
              }`}
            >
              {(anime.status ?? 'unknown').toUpperCase()}
            </span>
            <span className="inline-flex items-center gap-1.5 rounded-md bg-black/50 px-2.5 py-1 font-bold text-white">
              <svg viewBox="0 0 24 24" fill="currentColor" className="h-3 w-3 text-accent">
                <path d="m12 2 2.9 6.6 7.1.6-5.4 4.7 1.6 7-6.2-3.7-6.2 3.7 1.6-7L2 9.2l7.1-.6L12 2Z" />
              </svg>
              {anime.rating_avg === null ? '-' : Number(anime.rating_avg).toFixed(1)}
            </span>
          </div>

          {anime.synopsis ? (
            <p className="mt-6 max-w-xl text-sm leading-relaxed text-slate-300">{anime.synopsis}</p>
          ) : null}

          <div className="mt-8 flex flex-wrap items-center gap-3">
            {first ? (
              <Link
                href={`/watch/${first.id}`}
                className="inline-flex items-center gap-2 rounded-lg bg-accent px-5 py-3 text-sm font-bold text-night-950 transition hover:bg-accent-soft"
              >
                <PlayIcon />
                Mulai nonton
              </Link>
            ) : null}
            <span className="text-sm text-slate-400">
              <span className="font-semibold text-slate-200">{fmt(stats.episode_count)}</span> episode tersimpan
              {stats.max_number !== null ? ` · terakhir ep ${fmtEpisode(Number(stats.max_number))}` : ''}
            </span>
          </div>
        </div>
      </section>

      <div className="mx-auto max-w-7xl px-4 py-12 md:px-6">
        <SectionHead
          eyebrow="Daftar episode"
          icon={<PlayIcon />}
          title="Pilih episode"
          action={{ label: 'Katalog', href: '/' }}
        />

        {episodes.length === 0 ? (
          <div className="rounded-xl border border-white/5 bg-white/[0.03] p-10 text-center text-sm text-slate-400">
            Episode belum tersinkron untuk seri ini.
          </div>
        ) : (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
            {episodes.map((episode) => (
              <Link
                key={episode.id}
                href={`/watch/${episode.id}`}
                className="group flex items-center gap-4 rounded-xl border border-white/5 bg-white/[0.03] p-4 transition hover:border-accent/40 hover:bg-white/[0.06]"
              >
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-night-800 text-sm font-black tabular-nums text-accent ring-1 ring-inset ring-white/5">
                  {fmtEpisode(Number(episode.number))}
                </span>

                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-semibold text-white transition group-hover:text-accent-soft">
                    Episode {fmtEpisode(Number(episode.number))}
                  </span>
                  <span className="mt-0.5 block truncate text-xs text-slate-500">
                    {episode.title || 'Tanpa judul'} · {duration(episode.duration_sec)}
                  </span>
                </span>

                <span className="text-slate-500 transition group-hover:text-accent">
                  <PlayIcon />
                </span>
              </Link>
            ))}
          </div>
        )}
      </div>
    </MainLayout>
  );
}
