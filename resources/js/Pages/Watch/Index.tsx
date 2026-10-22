import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { fmtEpisode } from '@/Components/AnimeCard';

type Anime = {
  id: number;
  slug: string;
  title: string;
};

type Episode = {
  id: number;
  number: number;
  title: string;
  duration_sec: number | null;
};

type Props = {
  anime: Anime;
  episode: Episode;
  qualities: string[];
  defaultQuality: string | null;
  signMode: 'local' | 'guest';
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

const duration = (seconds: number | null): string => {
  if (!seconds || seconds <= 0) {
    return '-';
  }

  const m = Math.floor(seconds / 60);
  const s = seconds % 60;

  return `${m}m ${s.toString().padStart(2, '0')}s`;
};

const qualityLabel = (quality: string): string => {
  const q = quality.toLowerCase();

  if (q === '1080' || q === '720' || q === '480' || q === '360') {
    return `${quality}p`;
  }

  if (q === 'eng') {
    return 'English Dub';
  }

  if (q === 'hdeng') {
    return 'HD English Dub';
  }

  if (q === 'auto') {
    return 'Auto';
  }

  return quality;
};

const PlayIcon = () => (
  <svg viewBox="0 0 24 24" fill="currentColor" className="h-4 w-4">
    <path d="M8 5.5v13l11-6.5-11-6.5Z" />
  </svg>
);

export default function Index({ anime, episode, qualities, defaultQuality, signMode }: Props) {
  const [quality, setQuality] = useState(defaultQuality ?? qualities[0] ?? '720');
  const [streamUrl, setStreamUrl] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);

  const play = async () => {
    setLoading(true);
    setError(null);
    setInfo(null);
    setStreamUrl(null);

    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

      const headers = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
      };

      let nonce: string | undefined;

      if (signMode === 'guest') {
        const nres = await fetch('/api/guest/stream/nonce', {
          method: 'POST',
          headers,
          body: JSON.stringify({}),
        });

        const ndata = await nres.json();

        if (!nres.ok) {
          throw new Error(ndata?.error?.message ?? `HTTP ${nres.status}`);
        }

        nonce = ndata.nonce;
      }

      const response = await fetch(signMode === 'guest' ? '/api/guest/stream/sign' : '/api/local/stream/sign', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          episode_id: Number(episode.id),
          quality,
          ...(nonce ? { nonce } : {}),
        }),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data?.error?.message ?? `HTTP ${response.status}`);
      }

      setStreamUrl(data.stream_url);
      setInfo(
        `Token issued · TTL ${fmt(data.ttl)}s · quality ${qualityLabel(data.default_quality)} · available: ${data.qualities.map(qualityLabel).join(', ')}`
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal membuat token stream.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      <Head title={`${anime.title} · Episode ${fmtEpisode(Number(episode.number))}`} />

      <div className="mx-auto max-w-6xl px-4 py-8 md:px-6">
        <Link
          href={`/anime/${anime.slug}`}
          className="inline-flex items-center gap-2 text-xs font-semibold text-sky-300 transition hover:text-sky-200"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-3.5 w-3.5">
            <path d="M19 12H5m6 6-6-6 6-6" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
          Kembali ke {anime.title}
        </Link>

        <div className="mt-4 flex flex-wrap items-end justify-between gap-3">
          <div>
            <div className="flex items-center gap-3 text-[11px] font-bold uppercase tracking-[0.3em] text-accent">
              <span className="h-px w-6 bg-accent" />
              Sedang diputar
            </div>
            <h1 className="mt-3 text-2xl font-extrabold tracking-tight text-white md:text-3xl">
              {anime.title}
              <span className="text-sky-400">.</span>{' '}
              <span className="text-slate-400">Episode {fmtEpisode(Number(episode.number))}</span>
            </h1>
            <p className="mt-2 text-xs text-slate-500">
              {episode.title || 'Tanpa judul'} · durasi {duration(episode.duration_sec)}
            </p>
          </div>
        </div>

        <section className="mt-6 overflow-hidden rounded-2xl border border-white/5 bg-black shadow-card">
          {streamUrl ? (
            <video
              key={streamUrl}
              src={streamUrl}
              controls
              autoPlay
              playsInline
              preload="metadata"
              className="aspect-video w-full bg-black"
            />
          ) : (
            <div className="flex aspect-video w-full flex-col items-center justify-center gap-4 bg-gradient-to-br from-night-800 via-night-900 to-night-950">
              <span className="flex h-16 w-16 items-center justify-center rounded-2xl bg-accent/15 text-accent">
                <svg viewBox="0 0 24 24" fill="currentColor" className="h-7 w-7">
                  <path d="M8 5.5v13l11-6.5-11-6.5Z" />
                </svg>
              </span>
              <p className="text-sm text-slate-400">Klik PUTAR untuk membuat token dan memulai stream proxy.</p>
            </div>
          )}
        </section>

        <section className="mt-5 rounded-2xl border border-white/5 bg-white/[0.03] p-5">
          <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div className="min-w-0 flex-1">
              <label className="block text-xs font-semibold uppercase tracking-wide text-slate-400">Kualitas</label>
              <select
                value={quality}
                onChange={(event) => setQuality(event.target.value)}
                className="mt-2 h-11 w-full rounded-lg border border-white/10 bg-night-900 px-3 text-sm text-slate-100 outline-none focus:border-accent/40 focus:ring-2 focus:ring-accent/20 md:w-56"
              >
                {qualities.length === 0 ? (
                  <option value="">Tidak ada kualitas</option>
                ) : (
                  qualities.map((item) => (
                    <option key={item} value={item}>
                      {qualityLabel(item)}
                    </option>
                  ))
                )}
              </select>
            </div>

            <button
              type="button"
              onClick={play}
              disabled={loading || qualities.length === 0}
              className="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-lg bg-accent px-7 text-sm font-bold text-night-950 transition hover:bg-accent-soft disabled:cursor-not-allowed disabled:opacity-40"
            >
              <PlayIcon />
              {loading ? 'MEMBUAT TOKEN...' : 'PUTAR'}
            </button>
          </div>

          {qualities.length === 0 ? (
            <div className="mt-4 rounded-lg border border-rose-400/20 bg-rose-500/10 p-3 text-sm text-rose-200">
              Episode ini tidak punya kualitas stream yang tersedia.
            </div>
          ) : null}

          {error ? (
            <div className="mt-4 rounded-lg border border-rose-400/20 bg-rose-500/10 p-3 text-sm text-rose-200">{error}</div>
          ) : null}

          {info ? (
            <div className="mt-4 rounded-lg border border-emerald-400/20 bg-emerald-500/10 p-3 text-sm text-emerald-200">
              {info}
            </div>
          ) : null}
        </section>

        <section className="mt-5 grid gap-4 md:grid-cols-2">
          <div className="rounded-2xl border border-white/5 bg-white/[0.03] p-5">
            <h2 className="text-sm font-bold text-white">Identity</h2>
            <dl className="mt-3 space-y-2 text-xs text-slate-400">
              <div className="flex justify-between gap-4">
                <dt>Anime ID</dt>
                <dd className="font-medium tabular-nums text-slate-200">{fmt(anime.id)}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt>Episode ID</dt>
                <dd className="font-medium tabular-nums text-slate-200">{fmt(episode.id)}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt>Episode Number</dt>
                <dd className="font-medium tabular-nums text-slate-200">{fmtEpisode(Number(episode.number))}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt>Available Qualities</dt>
                <dd className="text-right font-medium text-slate-200">
                  {qualities.length > 0 ? qualities.map(qualityLabel).join(', ') : '-'}
                </dd>
              </div>
            </dl>
          </div>

          <div className="rounded-2xl border border-white/5 bg-white/[0.03] p-5">
            <h2 className="text-sm font-bold text-white">Stream Proxy</h2>
            <p className="mt-3 text-xs leading-relaxed text-slate-400">
              Player memakai endpoint <code className="text-slate-200">/stream/play/&#123;token&#125;</code>. Token
              diikat ke IP prefix, UA, episode, kualitas, dan expiry. URL upstream asli tidak dikirim ke browser.
            </p>
          </div>
        </section>
      </div>
    </>
  );
}
