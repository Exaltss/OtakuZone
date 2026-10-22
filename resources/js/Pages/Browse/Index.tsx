import { Head, Link, router } from '@inertiajs/react';

type Item = {
  id: number;
  slug: string;
  title: string;
  type: string | null;
  status: string | null;
  year: number | null;
  rating_avg: number | string | null;
  view_count: number | null;
  cat_id: number | null;
  image_path: string | null;
  episodes_total: number | null;
};

type Meta = {
  page: number;
  per_page: number;
  total: number;
  last_page: number;
  from: number;
  to: number;
};

type Filters = {
  letter: string;
  status: string;
  type: string;
  year: string;
  sort: string;
  group: string;
};

type Options = {
  years: number[];
  types: string[];
  groups: { id: number; name: string; slug: string }[];
  genres: { id: number; name: string; slug: string }[];
};

type Props = {
  items: Item[];
  meta: Meta;
  filters: Filters;
  options: Options;
};

const LETTERS = ['#', ...'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('')];
const nf = new Intl.NumberFormat('id-ID');

const fmt = (v: number | string | null | undefined): string => {
  if (v === null || v === undefined || v === '') return '-';
  const n = Number(v);
  return Number.isNaN(n) ? String(v) : nf.format(n);
};

const posterSrc = (path: string | null): string | null => {
  if (!path) return null;
  if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('/')) {
    return path;
  }
  return `/storage/${path.replace(/^\/+/, '')}`;
};

export default function BrowseIndex({ items, meta, filters, options }: Props) {
  const applyFilter = (patch: Partial<Filters>, nextPage = 1) => {
    const merged = { ...filters, ...patch, page: nextPage };
    router.get('/browse', merged, { preserveState: true, preserveScroll: true });
  };

  return (
    <>
      <Head title="Browse & A–Z Index" />

      <main className="mx-auto max-w-7xl px-4 py-6">
        <div className="mb-6 rounded-xl border border-white/10 bg-white/5 p-4">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h1 className="text-xl font-black text-white">Browse Katalog & Indeks A–Z</h1>
              <p className="text-xs text-slate-400">
                Menampilkan {fmt(meta.from)}–{fmt(meta.to)} dari {fmt(meta.total)} seri anime
              </p>
            </div>

            <button
              type="button"
              onClick={() =>
                applyFilter({ letter: '', status: '', type: '', year: '', sort: 'popular', group: '' }, 1)
              }
              className="self-start rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-slate-300 hover:bg-white/10"
            >
              Reset Filter
            </button>
          </div>

          <div className="mt-4 flex flex-wrap gap-1.5">
            <button
              type="button"
              onClick={() => applyFilter({ letter: '' })}
              className={`h-8 rounded-lg px-2.5 text-xs font-bold transition ${
                filters.letter === ''
                  ? 'bg-sky-500 text-slate-950'
                  : 'border border-white/10 bg-slate-900 text-slate-300 hover:bg-white/10'
              }`}
            >
              Semua
            </button>
            {LETTERS.map((ch) => (
              <button
                key={ch}
                type="button"
                onClick={() => applyFilter({ letter: ch })}
                className={`h-8 min-w-[2rem] rounded-lg px-2 text-xs font-bold transition ${
                  filters.letter === ch
                    ? 'bg-sky-500 text-slate-950'
                    : 'border border-white/10 bg-slate-900 text-slate-300 hover:bg-white/10'
                }`}
              >
                {ch}
              </button>
            ))}
          </div>

          <div className="mt-4 grid gap-3 sm:grid-cols-2 md:grid-cols-5">
            <select
              value={filters.status}
              onChange={(e) => applyFilter({ status: e.target.value })}
              className="h-10 rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-slate-100"
            >
              <option value="">Semua Status</option>
              <option value="ongoing">Ongoing</option>
              <option value="completed">Completed</option>
            </select>

            <select
              value={filters.type}
              onChange={(e) => applyFilter({ type: e.target.value })}
              className="h-10 rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-slate-100"
            >
              <option value="">Semua Tipe</option>
              {options.types.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </select>

            <select
              value={filters.year}
              onChange={(e) => applyFilter({ year: e.target.value })}
              className="h-10 rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-slate-100"
            >
              <option value="">Semua Tahun</option>
              {options.years.map((y) => (
                <option key={y} value={String(y)}>
                  {y}
                </option>
              ))}
            </select>

            <select
              value={filters.group}
              onChange={(e) => applyFilter({ group: e.target.value })}
              className="h-10 rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-slate-100"
            >
              <option value="">Semua Group</option>
              {options.groups.map((g) => (
                <option key={g.id} value={g.slug}>
                  {g.name}
                </option>
              ))}
            </select>

            <select
              value={filters.sort}
              onChange={(e) => applyFilter({ sort: e.target.value })}
              className="h-10 rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-slate-100"
            >
              <option value="popular">Urut: Terpopuler</option>
              <option value="title">Urut: Judul (A–Z)</option>
              <option value="latest">Urut: Terbaru</option>
              <option value="rating">Urut: Rating Tertinggi</option>
              <option value="year">Urut: Tahun Rilis</option>
            </select>
          </div>
        </div>

        {items.length === 0 ? (
          <div className="rounded-xl border border-white/10 bg-white/5 p-10 text-center text-sm text-slate-400">
            Tidak ada anime yang cocok dengan filter yang dipilih.
          </div>
        ) : (
          <section className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
            {items.map((item) => {
              const src = posterSrc(item.image_path);
              return (
                <article
                  key={item.id}
                  className="group overflow-hidden rounded-xl border border-white/10 bg-white/5 transition hover:border-sky-400/40"
                >
                  <Link href={`/anime/${item.slug}`} className="block">
                    {src ? (
                      <img
                        src={src}
                        alt={item.title}
                        loading="lazy"
                        className="aspect-[2/3] w-full object-cover"
                      />
                    ) : (
                      <div className="flex aspect-[2/3] items-center justify-center bg-gradient-to-br from-slate-800 via-slate-900 to-slate-950 text-5xl font-black text-white/15">
                        {item.title.trim().charAt(0).toUpperCase() || '?'}
                      </div>
                    )}
                  </Link>

                  <div className="space-y-2 p-3">
                    <h3 className="min-h-[2.5rem] text-sm font-semibold leading-snug text-white">
                      <Link href={`/anime/${item.slug}`} className="line-clamp-2 hover:text-sky-300">
                        {item.title}
                      </Link>
                    </h3>

                    <div className="flex flex-wrap gap-1 text-[11px]">
                      <span className="rounded bg-emerald-500/15 px-1.5 py-0.5 text-emerald-200">
                        {item.status ?? '-'}
                      </span>
                      <span className="rounded bg-amber-500/15 px-1.5 py-0.5 text-amber-200">
                        {item.year ?? '-'}
                      </span>
                      <span className="rounded bg-sky-500/15 px-1.5 py-0.5 text-sky-200">
                        {fmt(item.episodes_total)} ep
                      </span>
                    </div>
                  </div>
                </article>
              );
            })}
          </section>
        )}

        <nav className="mt-8 flex items-center justify-between rounded-xl border border-white/10 bg-white/5 p-4">
          <button
            type="button"
            disabled={meta.page <= 1}
            onClick={() => applyFilter({}, meta.page - 1)}
            className="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-white disabled:opacity-35"
          >
            Sebelumnya
          </button>
          <span className="text-xs text-slate-400">
            Halaman {meta.page} / {meta.last_page}
          </span>
          <button
            type="button"
            disabled={meta.page >= meta.last_page}
            onClick={() => applyFilter({}, meta.page + 1)}
            className="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-white disabled:opacity-35"
          >
            Berikutnya
          </button>
        </nav>
      </main>
    </>
  );
}
