import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

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

type Stats = {
  total: number;
  ongoing: number;
  completed: number;
  no_year: number;
};

type Filters = {
  q: string;
};

type Props = {
  items: Item[];
  meta: Meta;
  stats: Stats;
  filters: Filters;
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

const initial = (title: string): string => {
  const clean = title.trim();

  if (clean === '') {
    return '?';
  }

  return clean.charAt(0).toUpperCase();
};

const badge = (label: string, tone: string): string => {
  const tones: Record<string, string> = {
    sky: 'bg-sky-500/15 text-sky-200 ring-sky-400/20',
    emerald: 'bg-emerald-500/15 text-emerald-200 ring-emerald-400/20',
    amber: 'bg-amber-500/15 text-amber-200 ring-amber-400/20',
    slate: 'bg-slate-500/15 text-slate-200 ring-slate-400/20',
    rose: 'bg-rose-500/15 text-rose-200 ring-rose-400/20',
  };

  return `inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${tones[tone] ?? tones.slate}`;
};

export default function Index({ items, meta, stats, filters }: Props) {
  const [q, setQ] = useState(filters.q);

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    router.get(
      '/',
      { q, page: 1 },
      {
        preserveState: true,
        preserveScroll: true,
        replace: true,
      }
    );
  };

  const go = (page: number) => {
    if (page < 1 || page > meta.last_page) {
      return;
    }

    router.get(
      '/',
      { q: filters.q, page },
      {
        preserveState: true,
        preserveScroll: true,
      }
    );
  };

  return (
    <>
      <Head title="Katalog Anime" />

      <div className="min-h-screen">
        <header className="sticky top-0 z-20 border-b border-white/10 bg-slate-950/85 backdrop-blur">
          <div className="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-4 md:flex-row md:items-center md:justify-between">
            <div>
              <h1 className="text-2xl font-black tracking-tight text-white">
                Zonaotaku Catalog
              </h1>
              <p className="mt-1 text-xs text-slate-400">
                {fmt(stats.total)} seri tersimpan lokal · hasil filter {fmt(meta.total)} · halaman {meta.page}/{meta.last_page}
              </p>
            </div>

            <form onSubmit={submit} className="flex w-full max-w-md gap-2">
              <input
                value={q}
                onChange={(event) => setQ(event.target.value)}
                placeholder="Cari judul anime..."
                className="h-10 w-full rounded-lg border border-white/10 bg-white/5 px-3 text-sm text-slate-100 outline-none placeholder:text-slate-500 focus:border-sky-400/50 focus:ring-2 focus:ring-sky-400/20"
              />
              <button
                type="submit"
                className="h-10 shrink-0 rounded-lg bg-sky-500 px-4 text-sm font-semibold text-slate-950 transition hover:bg-sky-400"
              >
                Cari
              </button>
            </form>
          </div>
        </header>

        <main className="mx-auto max-w-7xl px-4 py-6">
          <section className="mb-5 rounded-xl border border-sky-400/20 bg-sky-500/10 p-4 text-sm text-sky-100">
            <div className="font-semibold">Mode inspeksi katalog + streaming lokal</div>
            <p className="mt-1 text-sky-200/80">
              Klik judul anime untuk membuka daftar episode. Klik episode untuk membuka player dan mengetes stream proxy.
            </p>
          </section>

          <section className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {[
              { label: 'Total Seri', value: stats.total, tone: 'sky' },
              { label: 'Ongoing', value: stats.ongoing, tone: 'emerald' },
              { label: 'Completed', value: stats.completed, tone: 'amber' },
              { label: 'Tanpa Tahun', value: stats.no_year, tone: 'rose' },
            ].map((card) => (
              <div
                key={card.label}
                className="rounded-xl border border-white/10 bg-white/5 p-4"
              >
                <div className="text-xs uppercase tracking-wide text-slate-400">
                  {card.label}
                </div>
                <div className="mt-2 text-2xl font-black tabular-nums text-white">
                  {fmt(card.value)}
                </div>
              </div>
            ))}
          </section>

          {items.length === 0 ? (
            <div className="rounded-xl border border-white/10 bg-white/5 p-10 text-center">
              <div className="text-lg font-semibold text-white">Tidak ada data katalog</div>
              <p className="mt-2 text-sm text-slate-400">
                Tabel anime masih kosong atau kata kunci pencarian tidak cocok.
              </p>
            </div>
          ) : (
            <section className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
              {items.map((item) => (
                <article
                  key={item.id}
                  className="group overflow-hidden rounded-xl border border-white/10 bg-white/5 transition hover:border-sky-400/40 hover:bg-white/[0.07]"
                >
                  <div className="flex aspect-[2/3] items-center justify-center bg-gradient-to-br from-slate-800 via-slate-900 to-slate-950 text-5xl font-black text-white/15">
                    {initial(item.title)}
                  </div>

                  <div className="space-y-3 p-3">
                    <h3 className="min-h-[2.5rem] text-sm font-semibold leading-snug text-white">
                      <Link
                        href={`/anime/${item.slug}`}
                        className="line-clamp-2 transition hover:text-sky-300"
                      >
                        {item.title}
                      </Link>
                    </h3>

                    <div className="flex flex-wrap gap-1">
                      <span className={badge(item.type ?? 'type?', 'sky')}>
                        {item.type ?? 'type?'}
                      </span>
                      <span className={badge(item.status ?? 'status?', 'emerald')}>
                        {item.status ?? 'status?'}
                      </span>
                      <span className={badge(item.year ? String(item.year) : 'year?', 'amber')}>
                        {item.year ?? 'year?'}
                      </span>
                    </div>

                    <dl className="grid grid-cols-2 gap-x-2 gap-y-1 text-[11px] text-slate-400">
                      <dt>Rating</dt>
                      <dd className="text-right font-medium tabular-nums text-slate-200">
                        {fmt(item.rating_avg)}
                      </dd>

                      <dt>Views</dt>
                      <dd className="text-right font-medium tabular-nums text-slate-200">
                        {fmt(item.view_count)}
                      </dd>

                      <dt>Cat ID</dt>
                      <dd className="text-right font-medium tabular-nums text-slate-200">
                        {fmt(item.cat_id)}
                      </dd>

                      <dt>Ep Total</dt>
                      <dd className="text-right font-medium tabular-nums text-slate-200">
                        {fmt(item.episodes_total)}
                      </dd>
                    </dl>
                  </div>
                </article>
              ))}
            </section>
          )}

          <nav className="mt-8 flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-white/5 p-4">
            <button
              type="button"
              onClick={() => go(meta.page - 1)}
              disabled={meta.page <= 1}
              className="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-100 transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-35"
            >
              Sebelumnya
            </button>

            <div className="text-center text-xs text-slate-400">
              <div className="font-semibold text-slate-200">
                Menampilkan {fmt(meta.from)}-{fmt(meta.to)} dari {fmt(meta.total)}
              </div>
              <div className="mt-1 tabular-nums">
                Halaman {meta.page} / {meta.last_page}
              </div>
            </div>

            <button
              type="button"
              onClick={() => go(meta.page + 1)}
              disabled={meta.page >= meta.last_page}
              className="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-100 transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-35"
            >
              Berikutnya
            </button>
          </nav>
        </main>
      </div>
    </>
  );
}
