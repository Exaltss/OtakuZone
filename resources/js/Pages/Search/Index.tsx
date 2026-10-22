import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Item = {
  id: number;
  slug: string;
  title: string;
  type: string | null;
  status: string | null;
  year: number | null;
  rating_avg: number | string | null;
  view_count: number | null;
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

type Props = {
  q: string;
  mode: string;
  items: Item[];
  meta: Meta;
};

const nf = new Intl.NumberFormat('id-ID');

export default function SearchIndex({ q: initialQ, mode, items, meta }: Props) {
  const [term, setTerm] = useState(initialQ);

  useEffect(() => {
    if (term === initialQ) return;
    const timer = setTimeout(() => {
      router.get('/search', { q: term, page: 1 }, { preserveState: true, replace: true });
    }, 300);
    return () => clearTimeout(timer);
  }, [term, initialQ]);

  return (
    <>
      <Head title="Pencarian Anime" />

      <main className="mx-auto max-w-7xl px-4 py-6">
        <div className="mb-6 rounded-xl border border-white/10 bg-white/5 p-5">
          <h1 className="text-xl font-black text-white">Pencarian Cepat (MySQL FULLTEXT)</h1>
          <p className="mt-1 text-xs text-slate-400">
            Ketik judul atau kata kunci sinopsis. Otomatis mencari dengan debounce 300ms.
          </p>

          <div className="mt-4 flex gap-2">
            <input
              value={term}
              onChange={(e) => setTerm(e.target.value)}
              placeholder="Contoh: Detective Conan, One Piece, Naruto..."
              className="h-11 w-full rounded-lg border border-white/10 bg-slate-900 px-4 text-sm text-white outline-none focus:border-sky-400"
            />
          </div>

          {initialQ !== '' && (
            <div className="mt-3 text-xs text-slate-400">
              Ditemukan <strong className="text-white">{nf.format(meta.total)}</strong> hasil untuk{' '}
              <strong className="text-sky-300">"{initialQ}"</strong> (mode: <code>{mode}</code>)
            </div>
          )}
        </div>

        {items.length > 0 && (
          <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
            {items.map((item) => (
              <Link
                key={item.id}
                href={`/anime/${item.slug}`}
                className="rounded-xl border border-white/10 bg-white/5 p-4 transition hover:border-sky-400/40 hover:bg-white/10"
              >
                <div className="font-bold text-white">{item.title}</div>
                <div className="mt-2 flex flex-wrap gap-1.5 text-[11px] text-slate-300">
                  <span className="rounded bg-emerald-500/15 px-2 py-0.5 text-emerald-200">
                    {item.status ?? '-'}
                  </span>
                  <span className="rounded bg-amber-500/15 px-2 py-0.5 text-amber-200">
                    {item.year ?? '-'}
                  </span>
                  <span className="rounded bg-sky-500/15 px-2 py-0.5 text-sky-200">
                    {item.episodes_total ?? 0} Episode
                  </span>
                </div>
              </Link>
            ))}
          </div>
        )}
      </main>
    </>
  );
}
