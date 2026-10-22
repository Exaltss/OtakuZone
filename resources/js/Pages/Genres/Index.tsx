import { Head, Link } from '@inertiajs/react';
import { SectionHead } from '@/Components/AnimeCard';

type GenreRow = {
  name: string;
  slug: string;
  count: number;
};

type Props = {
  genres: GenreRow[];
  stats: {
    genre_count: number;
    anime_count: number;
  };
};

const nf = new Intl.NumberFormat('id-ID');
const fmt = (value: number): string => nf.format(value);

const TagIcon = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-3.5 w-3.5">
    <path
      d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"
      strokeLinecap="round"
      strokeLinejoin="round"
    />
    <circle cx="7.5" cy="7.5" r="1.5" fill="currentColor" stroke="none" />
  </svg>
);

export default function Index({ genres, stats }: Props) {
  return (
    <>
      <Head title="Semua genre · Zonaotaku" />

      <div className="mx-auto max-w-7xl px-4 pb-16 pt-8 md:px-6">
        <SectionHead
          eyebrow="Jelajah berdasarkan selera"
          icon={<TagIcon />}
          title="Semua genre"
          action={{ label: 'Kembali ke beranda', href: '/' }}
        />

        <p className="-mt-3 mb-8 text-sm text-slate-400">
          <span className="font-semibold text-slate-200">{fmt(stats.genre_count)}</span> genre ·{' '}
          <span className="font-semibold text-slate-200">{fmt(stats.anime_count)}</span> anime ber-genre
          tersinkron. Pilih satu genre untuk melihat daftar animenya.
        </p>

        {genres.length === 0 ? (
          <div className="rounded-xl border border-white/5 bg-white/[0.03] p-12 text-center">
            <div className="text-lg font-bold text-white">Genre belum tersinkron</div>
            <p className="mt-2 text-sm text-slate-400">
              Jalankan <code className="text-slate-200">php artisan zo:backfill-genres</code> untuk mengisi
              genre dari katalog yang sudah tersinkron.
            </p>
          </div>
        ) : (
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
            {genres.map((genre) => (
              <Link
                key={genre.slug}
                href={`/?genre=${encodeURIComponent(genre.name)}`}
                className="group flex flex-col justify-between gap-3 rounded-xl border border-white/5 bg-white/[0.03] p-4 transition hover:border-accent/40 hover:bg-white/[0.06]"
              >
                <span className="flex items-start justify-between gap-2">
                  <span className="text-sm font-bold text-slate-100 transition group-hover:text-accent-soft">
                    # {genre.name}
                  </span>
                  <span className="text-slate-600 transition group-hover:text-accent">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-3.5 w-3.5">
                      <path d="M7 17 17 7M9 7h8v8" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                  </span>
                </span>
                <span className="text-xs tabular-nums text-slate-500">{fmt(genre.count)} anime</span>
              </Link>
            ))}
          </div>
        )}
      </div>
    </>
  );
}
