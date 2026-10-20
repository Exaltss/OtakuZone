import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

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

const hasEpisodeMarker = (title: string): boolean => {
  return /\b(?:eps?|episode|ep)\.?\s*#?\s*\d+/iu.test(title);
};

const episodeHundredths = (value: number): number => {
  return Math.round(value * 100);
};

const formatPlainNumber = (value: number): string => {
  const hundredths = episodeHundredths(value);
  const base = Math.floor(hundredths / 100);
  const frac = hundredths % 100;

  if (frac === 0) {
    return String(base);
  }

  return `${base},${frac.toString().padStart(2, '0')}`;
};

const episodeLabel = (episode: Episode): string => {
  const title = episode.title ?? '';
  const hundredths = episodeHundredths(Number(episode.number || 0));
  const base = Math.floor(hundredths / 100);
  const frac = hundredths % 100;

  if (!hasEpisodeMarker(title)) {
    return title || `Episode ${formatPlainNumber(Number(episode.number || 0))}`;
  }

  if (frac === 0) {
    return `Episode ${base}`;
  }

  return `Episode ${base} (varian ${frac})`;
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

export default function Index({ anime, episode, qualities, defaultQuality }: Props) {
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

      const response = await fetch('/api/local/stream/sign', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          episode_id: Number(episode.id),
          quality,
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
      <Head title={`${anime.title} · ${episodeLabel(episode)}`} />

      <div className="min-h-screen">
        <header className="border-b border-white/10 bg-slate-950/85">
          <div className="mx-auto max-w-6xl px-4 py-4">
            <Link
              href={`/anime/${anime.slug}`}
              className="text-xs font-semibold text-sky-300 hover:text-sky-200"
            >
              ← Kembali ke {anime.title}
            </Link>

            <div className="mt-3">
              <h1 className="text-2xl font-black tracking-tight text-white">
                {anime.title} · {episodeLabel(episode)}
              </h1>
              <p className="mt-1 text-xs text-slate-400">
                {episode.title} · episode_id {fmt(episode.id)} · durasi {duration(episode.duration_sec)}
              </p>
            </div>
          </div>
        </header>

        <main className="mx-auto max-w-6xl px-4 py-6">
          <section className="overflow-hidden rounded-xl border border-white/10 bg-black">
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
              <div className="flex aspect-video w-full items-center justify-center bg-gradient-to-br from-slate-900 via-slate-950 to-black text-sm text-slate-400">
                Klik PUTAR untuk membuat token dan mengetes stream proxy.
              </div>
            )}
          </section>

          <section className="mt-5 rounded-xl border border-white/10 bg-white/5 p-4">
            <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
              <div className="min-w-0 flex-1">
                <label className="block text-xs font-semibold uppercase tracking-wide text-slate-400">
                  Kualitas
                </label>

                <select
                  value={quality}
                  onChange={(event) => setQuality(event.target.value)}
                  className="mt-2 h-11 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-sm text-slate-100 outline-none focus:border-sky-400/50 focus:ring-2 focus:ring-sky-400/20 md:w-56"
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
                className="h-11 shrink-0 rounded-lg bg-sky-500 px-6 text-sm font-black text-slate-950 transition hover:bg-sky-400 disabled:cursor-not-allowed disabled:opacity-40"
              >
                {loading ? 'MEMBUAT TOKEN...' : 'PUTAR'}
              </button>
            </div>

            {qualities.length === 0 ? (
              <div className="mt-4 rounded-lg border border-rose-400/20 bg-rose-500/10 p-3 text-sm text-rose-200">
                Episode ini tidak punya kualitas stream yang tersedia.
              </div>
            ) : null}

            {error ? (
              <div className="mt-4 rounded-lg border border-rose-400/20 bg-rose-500/10 p-3 text-sm text-rose-200">
                {error}
              </div>
            ) : null}

            {info ? (
              <div className="mt-4 rounded-lg border border-emerald-400/20 bg-emerald-500/10 p-3 text-sm text-emerald-200">
                {info}
              </div>
            ) : null}
          </section>

          <section className="mt-5 grid gap-4 md:grid-cols-2">
            <div className="rounded-xl border border-white/10 bg-white/5 p-4">
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
                  <dd className="font-medium tabular-nums text-slate-200">
                    {formatPlainNumber(Number(episode.number || 0))}
                  </dd>
                </div>
                <div className="flex justify-between gap-4">
                  <dt>Available Qualities</dt>
                  <dd className="text-right font-medium text-slate-200">
                    {qualities.length > 0 ? qualities.map(qualityLabel).join(', ') : '-'}
                  </dd>
                </div>
              </dl>
            </div>

            <div className="rounded-xl border border-white/10 bg-white/5 p-4">
              <h2 className="text-sm font-bold text-white">Stream Proxy</h2>
              <p className="mt-3 text-xs leading-relaxed text-slate-400">
                Player memakai endpoint <code>/stream/play/&#123;token&#125;</code>. Token diikat ke IP prefix, UA, episode, kualitas, dan expiry. URL upstream asli tidak dikirim ke browser.
              </p>
            </div>
          </section>
        </main>
      </div>
    </>
  );
}
