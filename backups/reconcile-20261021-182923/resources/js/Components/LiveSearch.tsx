import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type QuickItem = {
  id: number;
  slug: string;
  title: string;
  type: string | null;
  status: string | null;
  year: number | null;
  rating_avg: number | null;
  poster: string | null;
};

const SearchIcon = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-4 w-4">
    <circle cx="11" cy="11" r="7" />
    <path d="m20 20-3.5-3.5" strokeLinecap="round" />
  </svg>
);

const PosterThumb = ({ src, title }: { src: string | null; title: string }) =>
  src ? (
    <img
      src={src}
      alt={title}
      loading="lazy"
      className="h-14 w-10 shrink-0 rounded-md object-cover ring-1 ring-inset ring-white/10"
    />
  ) : (
    <span className="flex h-14 w-10 shrink-0 items-center justify-center rounded-md bg-night-800 text-[10px] font-black text-accent ring-1 ring-inset ring-white/10">
      {title.slice(0, 2).toUpperCase()}
    </span>
  );

export default function LiveSearch() {
  const [q, setQ] = useState('');
  const [items, setItems] = useState<QuickItem[]>([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const boxRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    const query = q.trim();

    if (query === '') {
      setItems([]);
      setOpen(false);
      return;
    }

    const timer = window.setTimeout(async () => {
      setLoading(true);
      try {
        const res = await fetch(`/api/quick-search?q=${encodeURIComponent(query)}`, {
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        setItems(res.ok ? (data.items ?? []) : []);
        setOpen(true);
      } catch {
        setItems([]);
      } finally {
        setLoading(false);
      }
    }, 220);

    return () => window.clearTimeout(timer);
  }, [q]);

  useEffect(() => {
    const onDown = (event: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, []);

  const go = (slug: string) => {
    setOpen(false);
    setQ('');
    router.visit(`/anime/${slug}`);
  };

  return (
    <div ref={boxRef} className="relative">
      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-500">
        <SearchIcon />
      </span>
      <input
        value={q}
        onChange={(event) => setQ(event.target.value)}
        onFocus={() => q.trim() !== '' && setOpen(true)}
        onKeyDown={(event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            setOpen(false);
            window.location.href = q.trim() === '' ? '/' : `/?q=${encodeURIComponent(q.trim())}`;
          }
        }}
        placeholder="Ketik 1 huruf untuk cari..."
        className="h-10 w-44 rounded-lg border border-white/10 bg-white/5 pl-9 pr-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-accent/40 focus:ring-2 focus:ring-accent/20 md:w-64"
      />

      {open ? (
        <div className="absolute right-0 top-12 z-50 max-h-[70vh] w-[22rem] overflow-y-auto rounded-xl border border-white/10 bg-night-850 p-2 shadow-card">
          {loading ? (
            <div className="p-4 text-center text-sm text-slate-400">Mencari...</div>
          ) : items.length === 0 ? (
            <div className="p-4 text-center text-sm text-slate-500">Tidak ada hasil untuk “{q}”.</div>
          ) : (
            <ul className="space-y-1">
              {items.map((item) => (
                <li key={item.id}>
                  <button
                    type="button"
                    onClick={() => go(item.slug)}
                    className="flex w-full items-center gap-3 rounded-lg p-2 text-left transition hover:bg-white/[0.06]"
                  >
                    <PosterThumb src={item.poster} title={item.title} />
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-sm font-semibold text-slate-100">
                        {item.title}
                      </span>
                      <span className="mt-0.5 block truncate text-xs text-slate-500">
                        {item.year ?? '????'} · {item.status ?? '-'} ·{' '}
                        {item.rating_avg === null ? '-' : Number(item.rating_avg).toFixed(1)}★
                      </span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
          <div className="mt-1 border-t border-white/5 pt-2 text-center text-[11px] text-slate-500">
            Enter = hasil lengkap · klik = langsung ke detail
          </div>
        </div>
      ) : null}
    </div>
  );
}
