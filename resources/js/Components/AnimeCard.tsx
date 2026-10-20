import { Link } from '@inertiajs/react';
import { ReactNode, useState } from 'react';

export type CardItem = {
  id: number;
  slug: string;
  title: string;
  type: string | null;
  status: string | null;
  year: number | null;
  rating_avg: number | null;
  poster: string | null;
  episode_count: number;
  last_episode: number | null;
  latest_episode_id?: number;
};

const Star = ({ className = 'text-accent' }: { className?: string }) => (
  <svg viewBox="0 0 24 24" fill="currentColor" className={`h-3 w-3 ${className}`}>
    <path d="m12 2 2.9 6.6 7.1.6-5.4 4.7 1.6 7-6.2-3.7-6.2 3.7 1.6-7L2 9.2l7.1-.6L12 2Z" />
  </svg>
);

const ArrowUpRight = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-3.5 w-3.5">
    <path d="M7 17 17 7M9 7h8v8" strokeLinecap="round" strokeLinejoin="round" />
  </svg>
);

export const fmtEpisode = (value: number): string => {
  const hundredths = Math.round(value * 100);
  const base = Math.floor(hundredths / 100);
  const frac = hundredths % 100;

  return frac === 0 ? String(base) : `${base},${frac.toString().padStart(2, '0')}`;
};

/**
 * Poster dengan fallback gradasi bila gambar tidak ada / gagal dimuat
 * (image_path bisa null, thumbnail upstream bisa expired).
 */
export function Poster({ src, title, className = '' }: { src: string | null; title: string; className?: string }) {
  const [failed, setFailed] = useState(false);
  const initial = title.trim() === '' ? '?' : title.trim().charAt(0).toUpperCase();

  return (
    <div className={`relative h-full w-full overflow-hidden bg-gradient-to-br from-night-700 via-night-850 to-night-950 ${className}`}>
      {src && !failed ? (
        <img
          src={src}
          alt={title}
          loading="lazy"
          onError={() => setFailed(true)}
          className="absolute inset-0 h-full w-full object-cover transition duration-300 group-hover:scale-[1.04]"
        />
      ) : (
        <div className="absolute inset-0 flex items-center justify-center">
          <span className="text-5xl font-black text-white/10">{initial}</span>
        </div>
      )}
    </div>
  );
}

export function SectionHead({
  eyebrow,
  icon,
  title,
  action,
}: {
  eyebrow: string;
  icon?: ReactNode;
  title: string;
  action?: { label: string; href: string };
}) {
  return (
    <div className="mb-6 flex items-end justify-between gap-4">
      <div>
        <div className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.25em] text-slate-400">
          {icon ? <span className="text-accent">{icon}</span> : null}
          {eyebrow}
        </div>
        <h2 className="mt-2 text-2xl font-extrabold tracking-tight text-white md:text-3xl">
          {title}
          <span className="text-sky-400">.</span>
        </h2>
      </div>

      {action ? (
        <a
          href={action.href}
          className="inline-flex shrink-0 items-center gap-2 text-sm font-medium text-slate-300 transition hover:text-white"
        >
          {action.label}
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-4 w-4">
            <path d="M5 12h14m-6-6 6 6-6 6" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        </a>
      ) : null}
    </div>
  );
}

export function AnimeCard({ item }: { item: CardItem }) {
  const ongoing = (item.status ?? '').toLowerCase() === 'ongoing';
  const typeKey = ((item.type ?? '') + '').toLowerCase();
  const isMovie = typeKey === 'movie';

  return (
    <article className="group">
      <Link href={`/anime/${item.slug}`} className="block">
        <div className="relative aspect-[2/3] overflow-hidden rounded-xl border border-white/5 shadow-card">
          <Poster src={item.poster} title={item.title} />

          <div className="absolute inset-x-0 bottom-0 h-20 bg-gradient-to-t from-black/85 to-transparent" />

          <span className="absolute left-2 top-2 rounded-md bg-black/70 px-2 py-1 text-[10px] font-bold tracking-widest text-white">
            {isMovie ? 'MOVIE' : 'SERIES'}
          </span>

          {item.rating_avg !== null ? (
            <span className="absolute right-2 top-2 inline-flex items-center gap-1 rounded-md bg-black/70 px-2 py-1 text-[11px] font-bold text-white">
              <Star />
              {Number(item.rating_avg).toFixed(1)}
            </span>
          ) : null}

          <span className="absolute bottom-2 left-2 inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-slate-200">
            {ongoing ? (
              <>
                <span className="h-1.5 w-1.5 rounded-full bg-sky-400" />
                ONGOING
              </>
            ) : (
              <>{item.episode_count > 0 ? `${item.episode_count} episode` : (item.status ?? '').toUpperCase()}</>
            )}
          </span>

          <span className="absolute bottom-2 right-2 flex h-6 w-6 items-center justify-center rounded-md bg-black/60 text-slate-200 transition group-hover:bg-accent group-hover:text-night-950">
            <ArrowUpRight />
          </span>
        </div>

        <h3 className="mt-3 line-clamp-2 min-h-[2.5rem] text-sm font-semibold leading-snug text-white transition group-hover:text-accent-soft">
          {item.title}
        </h3>
      </Link>

      <p className="mt-1 text-xs text-slate-400">
        {ongoing ? 'Update Episode: ' : 'Episode Terakhir: '}
        <span className="font-semibold text-sky-400">
          {item.last_episode === null ? '-' : fmtEpisode(item.last_episode)}
        </span>
      </p>
      <p className="mt-1 text-xs text-slate-500">
        {item.year ?? '????'} · {isMovie ? 'Movie' : 'TV Series'}
      </p>
    </article>
  );
}
