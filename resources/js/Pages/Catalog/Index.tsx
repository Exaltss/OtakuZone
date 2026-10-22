import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { AnimeCard, CardItem, Poster, SectionHead, fmtEpisode } from '@/Components/AnimeCard';

type Meta = {
  page: number;
  per_page: number;
  total: number;
  last_page: number;
  from: number;
  to: number;
};

type Stats = {
  total: number;
  ongoing: number;
  completed: number;
  no_year: number;
};

type Featured = CardItem & {
  synopsis: string | null;
  image_path: string | null;
};

type Props = {
  mode: 'home' | 'browse';
  items: CardItem[];
  meta: Meta;
  stats: Stats;
  filters: { q: string; sort: string; type: string; genre: string };
  featured: Featured[];
  latest: CardItem[];
  picks: CardItem[];
  popular: CardItem[];
};

const nf = new Intl.NumberFormat('id-ID');
const fmt = (value: number): string => nf.format(value);
const pad2 = (value: number): string => value.toString().padStart(2, '0');

const PlayIcon = () => (
  <svg viewBox="0 0 24 24" fill="currentColor" className="h-4 w-4">
    <path d="M8 5.5v13l11-6.5-11-6.5Z" />
  </svg>
);

const ArrowRight = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-4 w-4">
    <path d="M5 12h14m-6-6 6 6-6 6" strokeLinecap="round" strokeLinejoin="round" />
  </svg>
);

function Hero({ featured }: { featured: Featured[] }) {
  const [index, setIndex] = useState(0);

  if (featured.length === 0) {
    return null;
  }

  const safeIndex = index % featured.length;
  const item = featured[safeIndex];
  const backdrop = item.poster ?? item.image_path;

  const prev = () => setIndex((safeIndex - 1 + featured.length) % featured.length);
  const next = () => setIndex((safeIndex + 1) % featured.length);

  return (
    <section className="relative overflow-hidden rounded-2xl border border-white/5 bg-night-850 shadow-card">
      <div className="absolute inset-0">
        <Poster src={backdrop} title={item.title} className="scale-105 blur-[2px]" />
        <div className="absolute inset-0 bg-gradient-to-r from-night-900 via-night-900/85 to-night-900/25" />
        <div className="absolute inset-0 bg-gradient-to-t from-night-900 via-transparent to-night-900/40" />
      </div>

      <div className="relative flex min-h-[420px] flex-col justify-center p-8 md:min-h-[460px] md:p-12">
        <div className="flex items-center gap-3 text-[11px] font-bold uppercase tracking-[0.3em] text-accent">
          <span className="h-px w-6 bg-accent" />
          Pilihan Zonaotaku
        </div>

        <div className="mt-5 flex flex-wrap items-center gap-2 text-sm text-slate-300">
          <span>{((item.type ?? '') + '').toLowerCase() === 'movie' ? 'Movie' : 'TV Series'}</span>
          <span className="text-slate-600">•</span>
          <span>{item.year ?? '????'}</span>
          <span className="text-slate-600">•</span>
          <span>SUB INDO</span>
        </div>

        <h1 className="mt-3 max-w-2xl text-4xl font-extrabold uppercase leading-[1.05] tracking-tight text-white md:text-6xl">
          {item.title}
          <span className="text-sky-400">.</span>
        </h1>

        {item.synopsis ? (
          <p className="mt-5 max-w-md text-sm leading-relaxed text-slate-300 line-clamp-3">{item.synopsis}</p>
        ) : null}

        <div className="mt-7 flex flex-wrap items-center gap-3">
          <Link
            href={item.latest_episode_id ? `/watch/${item.latest_episode_id}` : `/anime/${item.slug}`}
            className="inline-flex items-center gap-2 rounded-lg bg-accent px-5 py-3 text-sm font-bold text-night-950 transition hover:bg-accent-soft"
          >
            <PlayIcon />
            Mulai nonton
          </Link>
          <Link
            href={`/anime/${item.slug}`}
            className="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-5 py-3 text-sm font-semibold text-white transition hover:bg-white/10"
          >
            Detail anime
            <ArrowRight />
          </Link>
        </div>

        <div className="mt-10 flex items-center justify-between text-xs text-slate-400">
          <span>Cerita hebat, dalam satu tempat.</span>

          <div className="flex items-center gap-3">
            <span className="font-semibold tabular-nums text-slate-200">
              {pad2(safeIndex + 1)}
              <span className="text-slate-500">/{pad2(featured.length)}</span>
            </span>
            <button
              type="button"
              onClick={prev}
              aria-label="Sebelumnya"
              className="flex h-8 w-8 items-center justify-center rounded-full border border-white/10 bg-white/5 text-slate-200 transition hover:bg-white/10"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-3.5 w-3.5">
                <path d="m14 6-6 6 6 6" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </button>
            <button
              type="button"
              onClick={next}
              aria-label="Berikutnya"
              className="flex h-8 w-8 items-center justify-center rounded-full border border-white/10 bg-white/5 text-slate-200 transition hover:bg-white/10"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-3.5 w-3.5">
                <path d="m10 6 6 6-6 6" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </button>
          </div>
        </div>
      </div>

      {item.rating_avg !== null ? (
        <div className="absolute right-5 top-5 rounded-xl bg-night-950/80 px-4 py-3 text-center backdrop-blur">
          <div className="flex items-center justify-center gap-1.5 text-lg font-extrabold text-white">
            <svg viewBox="0 0 24 24" fill="currentColor" className="h-4 w-4 text-accent">
              <path d="m12 2 2.9 6.6 7.1.6-5.4 4.7 1.6 7-6.2-3.7-6.2 3.7 1.6-7L2 9.2l7.1-.6L12 2Z" />
            </svg>
            {Number(item.rating_avg).toFixed(1)}
          </div>
          <div className="mt-0.5 text-[10px] font-semibold uppercase tracking-widest text-slate-400">Rating</div>
        </div>
      ) : null}
    </section>
  );
}

function PopularList({ items }: { items: CardItem[] }) {
  return (
    <div>
      <div className="flex items-end justify-between gap-4">
        <div>
          <h2 className="text-2xl font-extrabold tracking-tight text-white">Lagi ramai</h2>
          <p className="mt-1.5 text-sm text-slate-400">Anime yang paling banyak diputar di server.</p>
        </div>
        <div className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.25em] text-sky-400">
          <svg viewBox="0 0 24 24" fill="currentColor" className="h-3.5 w-3.5 text-accent">
            <path d="M13.5 0.7c0.4 3-0.7 4.9-2.2 6.4-1.6 1.6-3.6 2.9-4.6 5.2-1.3 3.1-0.3 7 2.3 9.2 2.7 2.3 6.8 2.6 9.8 0.8 3.1-1.9 4.6-5.7 3.7-9.2-0.4-1.6-1.3-3-2.4-4.2-0.2 1-0.7 1.9-1.6 2.4 0.3-2.6-0.6-5.3-2.2-7.3C15.2 2.6 14.4 1.5 13.5 0.7Z" />
          </svg>
          Populer
        </div>
      </div>

      <ol className="mt-6">
        {items.map((item, position) => (
          <li key={item.id} className="border-b border-white/5 last:border-0">
            <Link href={`/anime/${item.slug}`} className="group flex items-center gap-4 py-4">
              <span
                className="w-10 shrink-0 text-3xl font-black tabular-nums text-transparent"
                style={{ WebkitTextStroke: '1px #475569' }}
              >
                {pad2(position + 1)}
              </span>

              <span className="h-14 w-10 shrink-0 overflow-hidden rounded-md border border-white/5">
                <Poster src={item.poster} title={item.title} />
              </span>

              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-bold text-white transition group-hover:text-accent-soft">
                  {item.title}
                </span>
                <span className="mt-0.5 block text-xs text-slate-500">
                  {item.year ?? '????'} · {item.episode_count > 0 ? `${item.episode_count} episode` : 'belum ada episode'}
                </span>
              </span>

              <span className="inline-flex shrink-0 items-center gap-1.5 text-sm font-semibold text-sky-300">
                <svg viewBox="0 0 24 24" fill="currentColor" className="h-3.5 w-3.5 text-sky-400">
                  <path d="m12 2 2.9 6.6 7.1.6-5.4 4.7 1.6 7-6.2-3.7-6.2 3.7 1.6-7L2 9.2l7.1-.6L12 2Z" />
                </svg>
                {item.rating_avg === null ? '-' : Number(item.rating_avg).toFixed(1)}
              </span>
            </Link>
          </li>
        ))}
      </ol>

      <a
        href="/?sort=views"
        className="mt-5 inline-flex items-center gap-2 text-sm font-medium text-slate-300 transition hover:text-white"
      >
        Lihat peringkat anime
        <ArrowRight />
      </a>
    </div>
  );
}

function MoviePanel({ picks }: { picks: CardItem[] }) {
  const posters = picks.slice(0, 3);
  const tilts = ['-rotate-6', 'rotate-2', 'rotate-6'];

  return (
    <div className="flex h-full flex-col rounded-2xl border border-white/5 bg-night-850 p-8 shadow-card">
      <div className="text-[11px] font-semibold uppercase tracking-[0.3em] text-slate-400">
        Satu film. Banyak rasa.
      </div>

      <h3 className="mt-4 text-3xl font-extrabold leading-tight tracking-tight text-white">
        Untuk malam
        <br />
        yang lebih panjang<span className="text-sky-400">.</span>
      </h3>

      <p className="mt-4 max-w-sm text-sm leading-relaxed text-slate-400">
        Petualangan singkat yang bisa tinggal di pikiranmu lebih lama. Temukan anime movie
        berikutnya dari katalog lokal.
      </p>

      <a
        href="/?type=movie"
        className="mt-6 inline-flex w-fit items-center gap-2 rounded-lg bg-accent px-5 py-3 text-sm font-bold text-night-950 transition hover:bg-accent-soft"
      >
        Pilih movie
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-4 w-4">
          <path d="M7 17 17 7M9 7h8v8" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </a>

      <div className="mt-8 flex items-center gap-3">
        {posters.map((item, position) => (
          <Link
            key={item.id}
            href={`/anime/${item.slug}`}
            className={`h-36 w-24 overflow-hidden rounded-lg border border-white/10 shadow-card transition hover:z-10 hover:scale-105 ${tilts[position] ?? ''}`}
          >
            <Poster src={item.poster} title={item.title} />
          </Link>
        ))}
        {posters.length === 0 ? (
          <div className="text-sm text-slate-500">Belum ada movie tersinkron.</div>
        ) : null}
      </div>

      <div className="mt-auto pt-8 text-[10px] font-semibold uppercase tracking-[0.35em] text-slate-500">
        01 / Movie Night
      </div>
    </div>
  );
}

export default function Index({ mode, items, meta, stats, filters, featured, latest, picks, popular }: Props) {
  const home = mode === 'home';

  const pageHref = (page: number) => {
    const params = new URLSearchParams();

    if (filters.q !== '') params.set('q', filters.q);
    if (filters.sort !== '') params.set('sort', filters.sort);
    if (filters.type !== '') params.set('type', filters.type);
    if (filters.genre !== '') params.set('genre', filters.genre);
    if (page > 1) params.set('page', String(page));

    const query = params.toString();

    return query === '' ? '/' : `/?${query}`;
  };

  return (
    <>
      <Head title={home ? 'Nonton Anime Sub Indo' : `Jelajah anime · halaman ${meta.page}`} />

      <div className="mx-auto max-w-7xl px-4 pb-16 pt-6 md:px-6">
        {home ? (
          <>
            <div className="mb-5 flex items-center gap-2 text-sm text-slate-400">
              <span>
                <span className="font-semibold text-slate-200">{fmt(stats.total)}</span> anime untuk dijelajahi
              </span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-3.5 w-3.5 text-accent">
                <path d="M7 17 17 7M9 7h8v8" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </div>

            <Hero featured={featured} />

            <section className="mt-10 rounded-2xl border border-sky-400/10 bg-gradient-to-r from-night-800 to-night-850 p-6 md:p-7">
              <div className="flex flex-col items-start gap-5 md:flex-row md:items-center">
                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-accent/15 text-accent">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-5 w-5">
                    <path d="M12 8v4l2.5 2.5" strokeLinecap="round" strokeLinejoin="round" />
                    <circle cx="12" cy="12" r="9" />
                  </svg>
                </span>

                <div className="min-w-0 flex-1">
                  <div className="text-[11px] font-semibold uppercase tracking-[0.25em] text-sky-300">
                    Sinkron otomatis
                  </div>
                  <h2 className="mt-1.5 text-xl font-extrabold text-white md:text-2xl">
                    Episode baru tiap <span className="text-accent">15 menit</span>
                  </h2>
                  <p className="mt-1 text-sm text-slate-400">
                    Katalog disinkronkan langsung dari upstream Animeku — {fmt(stats.ongoing)} seri ongoing terpantau.
                  </p>
                </div>

                <a
                  href="#rilis"
                  className="inline-flex shrink-0 items-center gap-2 rounded-lg bg-accent px-5 py-3 text-sm font-bold text-night-950 transition hover:bg-accent-soft"
                >
                  Lihat rilis terbaru
                  <ArrowRight />
                </a>
              </div>
            </section>

            <section id="rilis" className="mt-14">
              <SectionHead
                eyebrow="Cerita yang baru dimulai"
                icon={
                  <svg viewBox="0 0 24 24" fill="currentColor" className="h-3.5 w-3.5">
                    <path d="M12 2 9.1 8.6 2 9.2l5.4 4.7-1.6 7 6.2-3.7 6.2 3.7-1.6-7L22 9.2l-7.1-.6L12 2Z" />
                  </svg>
                }
                title="Rilis terbaru"
                action={{ label: 'Lihat semua', href: '/?sort=updated' }}
              />

              {latest.length === 0 ? (
                <div className="rounded-xl border border-white/5 bg-white/[0.03] p-10 text-center text-sm text-slate-400">
                  Katalog masih kosong. Jalankan <code className="text-slate-200">php artisan sync:latest</code> dulu.
                </div>
              ) : (
                <div className="grid grid-cols-2 gap-5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                  {latest.map((item) => (
                    <AnimeCard key={item.id} item={item} />
                  ))}
                </div>
              )}
            </section>

            <section className="mt-16">
              <SectionHead
                eyebrow="Pilihan untuk watchlist"
                icon={
                  <svg viewBox="0 0 24 24" fill="currentColor" className="h-3.5 w-3.5">
                    <path d="M12 21s-7.5-4.7-10-9.3C0.4 8.6 2.4 5 6 5c2 0 3.4 1.1 4 2.2C10.6 6.1 12 5 14 5c3.6 0 5.6 3.6 4 6.7C19.5 16.3 12 21 12 21Z" />
                  </svg>
                }
                title="Sayang kalau dilewatkan"
                action={{ label: 'Jelajah semua', href: '/' }}
              />

              <div className="grid grid-cols-2 gap-5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                {picks.map((item) => (
                  <AnimeCard key={item.id} item={item} />
                ))}
              </div>
            </section>

            <section className="mt-16 grid gap-8 lg:grid-cols-2">
              <MoviePanel picks={picks} />
              <PopularList items={popular} />
            </section>

            <section className="mt-16 flex flex-col items-start justify-between gap-5 border-t border-white/5 pt-10 md:flex-row md:items-center">
              <div className="flex items-start gap-4">
                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-sky-500/10 text-sky-400">
                  <PlayIcon />
                </span>
                <div>
                  <h2 className="text-lg font-extrabold text-white">Katalog lokal adalah awal petualangan.</h2>
                  <p className="mt-1 text-sm text-slate-400">
                    Semua episode tersimpan & diproksi di server sendiri. Kembali kapan saja, tanpa iklan pop-up.
                  </p>
                </div>
              </div>

              <a
                href="/"
                className="inline-flex shrink-0 items-center gap-2 text-sm font-semibold text-white transition hover:text-accent-soft"
              >
                Jelajah katalog
                <ArrowRight />
              </a>
            </section>
          </>
        ) : (
          <>
            <SectionHead
              eyebrow={filters.genre !== '' ? 'Filter genre' : filters.q !== '' ? 'Hasil pencarian' : filters.type === 'movie' ? 'Koleksi movie' : 'Katalog lokal'}
              icon={
                <svg viewBox="0 0 24 24" fill="currentColor" className="h-3.5 w-3.5">
                  <path d="M12 2 9.1 8.6 2 9.2l5.4 4.7-1.6 7 6.2-3.7 6.2 3.7-1.6-7L22 9.2l-7.1-.6L12 2Z" />
                </svg>
              }
              title={
                filters.genre !== ''
                  ? `Genre: ${filters.genre}`
                  : filters.q !== ''
                  ? `“${filters.q}”`
                  : filters.type === 'movie'
                    ? 'Movie'
                    : filters.sort === 'rating'
                      ? 'Anime terbaik'
                      : 'Jelajah anime'
              }
            />

            <p className="-mt-3 mb-6 text-sm text-slate-400">
              Menampilkan {fmt(meta.from)}-{fmt(meta.to)} dari {fmt(meta.total)} hasil · halaman {meta.page}/
              {meta.last_page}
              {filters.sort === 'title'
                ? ' · urut A-Z'
                : filters.sort === 'rating'
                  ? ' · urut rating tertinggi'
                  : filters.sort === 'views'
                    ? ' · urut terpopuler'
                    : filters.sort === 'updated'
                      ? ' · urut update terbaru'
                      : ''}
              {filters.genre !== '' ? (
                <>
                  {' · '}
                  <a href="/genres" className="font-semibold text-accent transition hover:text-accent-soft">
                    ganti genre
                  </a>
                </>
              ) : null}
            </p>

            {items.length === 0 ? (
              <div className="rounded-xl border border-white/5 bg-white/[0.03] p-12 text-center">
                <div className="text-lg font-bold text-white">Tidak ada hasil</div>
                <p className="mt-2 text-sm text-slate-400">
                  Coba kata kunci lain, atau jalankan sinkronisasi bila katalog masih kosong.
                </p>
                <a href="/" className="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-accent">
                  Kembali ke beranda
                  <ArrowRight />
                </a>
              </div>
            ) : (
              <div className="grid grid-cols-2 gap-5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                {items.map((item) => (
                  <AnimeCard key={item.id} item={item} />
                ))}
              </div>
            )}

            <nav className="mt-10 flex items-center justify-between gap-3">
              {meta.page > 1 ? (
                <a
                  href={pageHref(meta.page - 1)}
                  className="rounded-lg border border-white/10 bg-white/5 px-4 py-2.5 text-sm font-semibold text-slate-100 transition hover:bg-white/10"
                >
                  Sebelumnya
                </a>
              ) : (
                <span />
              )}

              <span className="text-xs tabular-nums text-slate-500">
                Halaman {meta.page} / {meta.last_page}
              </span>

              {meta.page < meta.last_page ? (
                <a
                  href={pageHref(meta.page + 1)}
                  className="rounded-lg border border-white/10 bg-white/5 px-4 py-2.5 text-sm font-semibold text-slate-100 transition hover:bg-white/10"
                >
                  Berikutnya
                </a>
              ) : (
                <span />
              )}
            </nav>
          </>
        )}
      </div>
    </>
  );
}
