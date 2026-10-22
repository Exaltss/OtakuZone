import { Link, usePage } from '@inertiajs/react';
import { ReactNode } from 'react';
import LiveSearch from '@/Components/LiveSearch';

type SharedProps = {
  appName?: string;
  auth?: {
    user: { id: number; name: string; email: string; role: string; is_admin: boolean } | null;
  };
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
  const { auth } = usePage<SharedProps>().props;

  const logout = async () => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    await fetch('/logout', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    window.location.href = '/';
  };

  const nav = [
    { label: 'Beranda', href: '/' },
    { label: 'Jelajah anime', href: '/browse' },
    { label: 'Anime terbaik', href: '/?sort=rating' },
    { label: 'Movie', href: '/?type=movie' },
    { label: 'Genre', href: '/genres' },
    ...(auth?.user ? [{ label: 'Koleksi', href: '/library' }] : []),
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

          <div className="ml-auto flex items-center gap-3">
            <LiveSearch />

            {auth?.user ? (
              <>
                {auth.user.is_admin ? (
                  <a
                    href="/admin"
                    className="hidden rounded-lg border border-white/10 bg-white/[0.04] px-3 py-2 text-xs font-bold text-slate-200 transition hover:border-accent/40 hover:text-accent-soft md:inline-flex"
                  >
                    Admin
                  </a>
                ) : null}
                <span className="hidden max-w-[10rem] truncate text-xs text-slate-400 lg:block">
                  {auth.user.name}
                </span>
                <button
                  type="button"
                  onClick={logout}
                  className="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/[0.04] px-3 py-2 text-xs font-bold text-slate-200 transition hover:border-rose-400/40 hover:text-rose-200"
                >
                  Logout
                </button>
              </>
            ) : (
              <a
                href="/login"
                className="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2 text-xs font-bold text-night-950 transition hover:bg-accent-soft"
              >
                Login
              </a>
            )}
          </div>
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
