import { Link, usePage } from '@inertiajs/react';
import { FormEvent, ReactNode, useState } from 'react';

type SharedProps = {
  appName?: string;
};

const SearchIcon = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-4 w-4">
    <circle cx="11" cy="11" r="7" />
    <path d="m20 20-3.5-3.5" strokeLinecap="round" />
  </svg>
);

const ArrowUpRight = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-3.5 w-3.5">
    <path d="M7 17 17 7M9 7h8v8" strokeLinecap="round" strokeLinejoin="round" />
  </svg>
);

export function Logo() {
  const { appName } = usePage<SharedProps>().props;
  const name = (appName ?? 'Zonaotaku').toLowerCase();

  return (
    <Link href="/" className="flex items-center gap-2.5">
      <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-accent-soft to-accent-deep shadow-lg shadow-accent/20">
        <svg viewBox="0 0 24 24" fill="currentColor" className="h-4 w-4 text-night-950">
          <path d="M8 5.5v13l11-6.5-11-6.5Z" />
        </svg>
      </span>
      <span className="text-lg font-extrabold tracking-tight text-white">
        {name}
        <span className="text-accent">.</span>
      </span>
    </Link>
  );
}

export default function MainLayout({ children }: { children: ReactNode }) {
  const [q, setQ] = useState('');

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    window.location.href = q.trim() === '' ? '/' : `/?q=${encodeURIComponent(q.trim())}`;
  };

  const nav = [
    { label: 'Beranda', href: '/' },
    { label: 'Jelajah anime', href: '/?sort=title' },
    { label: 'Anime terbaik', href: '/?sort=rating' },
    { label: 'Movie', href: '/?type=movie' },
    { label: 'Genre', href: '/#genre' },
  ];

  return (
    <div className="flex min-h-screen flex-col bg-night-900">
      <header className="sticky top-0 z-40 border-b border-white/5 bg-night-950/85 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-7xl items-center gap-6 px-4 md:px-6">
          <Logo />

          <nav className="hidden items-center gap-6 lg:flex">
            {nav.map((item) => (
              <a
                key={item.label}
                href={item.href}
                className="text-sm font-medium text-slate-300 transition hover:text-white"
              >
                {item.label}
              </a>
            ))}
          </nav>

          <form onSubmit={submit} className="ml-auto flex items-center">
            <div className="relative">
              <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-500">
                <SearchIcon />
              </span>
              <input
                value={q}
                onChange={(event) => setQ(event.target.value)}
                placeholder="Cari anime favoritmu..."
                className="h-10 w-44 rounded-lg border border-white/10 bg-white/5 pl-9 pr-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-accent/40 focus:ring-2 focus:ring-accent/20 md:w-64"
              />
            </div>
          </form>
        </div>
      </header>

      <main className="flex-1">{children}</main>

      <footer className="border-t border-white/5 bg-night-950">
        <div className="mx-auto max-w-7xl px-4 pb-8 pt-10 md:px-6">
          <Logo />

          <p className="mt-5 max-w-4xl text-sm leading-relaxed text-slate-400">
            Zonaotaku adalah situs nonton anime sub Indo dengan katalog tersimpan lokal:
            episode terbaru, streaming terproksi di server sendiri, dan koleksi yang terus
            disinkronkan otomatis. Nikmati streaming anime dengan mudah, nyaman, dan praktis.
          </p>

          <div className="mt-7 flex flex-wrap gap-x-8 gap-y-3">
            {[
              { label: 'Semua anime', href: '/?sort=title' },
              { label: 'Rilis terbaru', href: '/?sort=updated' },
              { label: 'Anime terbaik', href: '/?sort=rating' },
              { label: 'Movie', href: '/?type=movie' },
            ].map((item) => (
              <a
                key={item.label}
                href={item.href}
                className="inline-flex items-center gap-1.5 text-sm text-slate-300 transition hover:text-white"
              >
                {item.label}
                <ArrowUpRight />
              </a>
            ))}
          </div>

          <div className="mt-8 flex flex-col gap-2 border-t border-white/5 pt-5 text-xs text-slate-500 md:flex-row md:items-center md:justify-between">
            <span>© 2026 Zonaotaku</span>
            <span>Metadata anime: Animeku</span>
          </div>
        </div>
      </footer>
    </div>
  );
}
