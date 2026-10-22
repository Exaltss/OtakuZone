import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Props = {
  stats: {
    anime_total: number;
    anime_ongoing: number;
    anime_completed: number;
    anime_hidden: number;
    anime_merged: number;
    episode_total: number;
    episode_with_stream: number;
    users_total: number;
  };
  breakerStatuses: {
    base_url: string;
    status: string;
    failures: number;
    allowed: boolean;
  }[];
  sourceConfig: {
    base_url_primary: string;
    base_url_fallback_1: string;
    base_url_fallback_2: string;
    worker_relay_url: string;
    stream_header_profile: string;
    has_api_key: boolean;
  };
  syncStates: {
    job_key: string;
    last_page_ok: number;
    cursor: string;
    last_status: string;
    last_run_at: string;
  }[];
  users: {
    id: number;
    name: string;
    email: string;
    role: string;
    banned_at: string | null;
    last_login_at: string | null;
  }[];
  groups: { id: number; name: string; slug: string; items_count: number }[];
  filterRules: { id: number; name: string; scope: string; priority: number; is_active: boolean }[];
  auditLogs: {
    id: number;
    user_id: number | null;
    action: string;
    target_type: string | null;
    target_id: number | null;
    created_at: string;
  }[];
};

const nf = new Intl.NumberFormat('id-ID');

export default function AdminDashboard({
  stats,
  breakerStatuses,
  sourceConfig,
  syncStates,
  users,
  groups,
  filterRules,
  auditLogs,
}: Props) {
  const [tab, setTab] = useState<'overview' | 'source' | 'content' | 'users' | 'audit'>('overview');

  const sourceForm = useForm({
    base_url_primary: sourceConfig.base_url_primary,
    base_url_fallback_1: sourceConfig.base_url_fallback_1,
    base_url_fallback_2: sourceConfig.base_url_fallback_2,
    api_key: '',
    worker_relay_url: sourceConfig.worker_relay_url,
    stream_header_profile: sourceConfig.stream_header_profile || 'default',
  });

  const moveForm = useForm({
    episode_id: '',
    target_anime_id: '',
    new_number: '',
    reason: 'Perbaikan episode terpisah',
  });

  const mergeForm = useForm({
    source_anime_id: '',
    target_anime_id: '',
    reason: 'Gabung duplikat seri',
  });

  const userForm = useForm({
    name: '',
    email: '',
    password: '',
    role: 'user',
  });

  const submitSource = (e: FormEvent) => {
    e.preventDefault();
    sourceForm.post('/admin/source-config');
  };

  const submitMove = (e: FormEvent) => {
    e.preventDefault();
    moveForm.post('/admin/episodes/move', { onSuccess: () => moveForm.reset() });
  };

  const submitMerge = (e: FormEvent) => {
    e.preventDefault();
    mergeForm.post('/admin/anime/merge', { onSuccess: () => mergeForm.reset() });
  };

  const submitUser = (e: FormEvent) => {
    e.preventDefault();
    userForm.post('/admin/users', { onSuccess: () => userForm.reset() });
  };

  return (
    <>
      <Head title="Admin CMS" />

      <main className="mx-auto max-w-7xl px-4 py-6">
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-white/10 bg-white/5 p-4">
          <div>
            <h1 className="text-xl font-black text-white">Zonaotaku Admin CMS (§8 Blueprint)</h1>
            <p className="text-xs text-slate-400">
              Source Hot-Swap · Move Episode · Merge Anime Tombstone · User & Audit Management
            </p>
          </div>

          <div className="flex flex-wrap gap-1.5">
            {[
              { id: 'overview', label: 'Overview & Sync' },
              { id: 'source', label: 'Source Config (G4)' },
              { id: 'content', label: 'Move Ep & Merge Anime' },
              { id: 'users', label: `Users (${users.length})` },
              { id: 'audit', label: 'Audit Logs' },
            ].map((item) => (
              <button
                key={item.id}
                type="button"
                onClick={() => setTab(item.id as any)}
                className={`rounded-lg px-3 py-2 text-xs font-bold transition ${
                  tab === item.id
                    ? 'bg-sky-500 text-slate-950'
                    : 'border border-white/10 bg-slate-900 text-slate-300 hover:bg-white/10'
                }`}
              >
                {item.label}
              </button>
            ))}
          </div>
        </div>

        {tab === 'overview' && (
          <div className="space-y-6">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              {[
                { label: 'Total Anime', value: nf.format(stats.anime_total) },
                { label: 'Total Episode', value: nf.format(stats.episode_total) },
                { label: 'Episode dgn Stream', value: nf.format(stats.episode_with_stream) },
                { label: 'Total Pengguna', value: nf.format(stats.users_total) },
              ].map((c) => (
                <div key={c.label} className="rounded-xl border border-white/10 bg-white/5 p-4">
                  <div className="text-xs uppercase text-slate-400">{c.label}</div>
                  <div className="mt-2 text-2xl font-black text-white">{c.value}</div>
                </div>
              ))}
            </div>

            <div className="rounded-xl border border-white/10 bg-white/5 p-4">
              <div className="flex items-center justify-between">
                <h2 className="text-sm font-bold text-white">Status Circuit Breaker & Resolver Chain</h2>
                <button
                  type="button"
                  onClick={() => router.post('/admin/source-test')}
                  className="rounded-lg bg-sky-500 px-3 py-1.5 text-xs font-bold text-slate-950 hover:bg-sky-400"
                >
                  Test Connection Sekarang
                </button>
              </div>
              <div className="mt-3 space-y-2 text-xs">
                {breakerStatuses.map((b) => (
                  <div
                    key={b.base_url}
                    className="flex items-center justify-between rounded-lg border border-white/10 bg-slate-900 px-3 py-2"
                  >
                    <span className="font-mono text-slate-200">{b.base_url}</span>
                    <span
                      className={`rounded px-2 py-0.5 font-bold ${
                        b.allowed ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'
                      }`}
                    >
                      {b.status.toUpperCase()} (fail: {b.failures})
                    </span>
                  </div>
                ))}
              </div>
            </div>

            <div className="rounded-xl border border-white/10 bg-white/5 p-4">
              <h2 className="text-sm font-bold text-white">Riwayat Sync State Terakhir</h2>
              <div className="mt-3 overflow-x-auto">
                <table className="min-w-full divide-y divide-white/10 text-xs">
                  <thead>
                    <tr className="text-left text-slate-400">
                      <th className="py-2 pr-4">Job Key</th>
                      <th className="py-2 pr-4">Status</th>
                      <th className="py-2 pr-4">Last Page OK</th>
                      <th className="py-2 pr-4">Cursor</th>
                      <th className="py-2">Waktu</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-white/5 text-slate-200">
                    {syncStates.map((s) => (
                      <tr key={s.job_key}>
                        <td className="py-2 pr-4 font-semibold">{s.job_key}</td>
                        <td className="py-2 pr-4">{s.last_status}</td>
                        <td className="py-2 pr-4">{s.last_page_ok}</td>
                        <td className="py-2 pr-4">{s.cursor}</td>
                        <td className="py-2">{s.last_run_at}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {tab === 'source' && (
          <form onSubmit={submitSource} className="space-y-4 rounded-xl border border-white/10 bg-white/5 p-5">
            <h2 className="text-base font-bold text-white">
              Hot-Swap Konfigurasi Upstream Animeku (Terenkripsi di Tabel Settings)
            </h2>
            <div className="grid gap-4 md:grid-cols-2">
              <div>
                <label className="block text-xs font-semibold text-slate-300">Primary Base URL</label>
                <input
                  value={sourceForm.data.base_url_primary}
                  onChange={(e) => sourceForm.setData('base_url_primary', e.target.value)}
                  className="mt-1 h-10 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-white"
                />
              </div>
              <div>
                <label className="block text-xs font-semibold text-slate-300">Fallback Base URL 1</label>
                <input
                  value={sourceForm.data.base_url_fallback_1}
                  onChange={(e) => sourceForm.setData('base_url_fallback_1', e.target.value)}
                  className="mt-1 h-10 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-white"
                />
              </div>
              <div>
                <label className="block text-xs font-semibold text-slate-300">
                  API Key Baru (Kosongkan jika tidak diganti)
                </label>
                <input
                  type="password"
                  placeholder={sourceConfig.has_api_key ? '•••••••• (Sudah tersimpan)' : 'Masukkan API Key'}
                  value={sourceForm.data.api_key}
                  onChange={(e) => sourceForm.setData('api_key', e.target.value)}
                  className="mt-1 h-10 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-white"
                />
              </div>
              <div>
                <label className="block text-xs font-semibold text-slate-300">
                  Cloudflare Worker Relay URL (Opsional)
                </label>
                <input
                  value={sourceForm.data.worker_relay_url}
                  onChange={(e) => sourceForm.setData('worker_relay_url', e.target.value)}
                  className="mt-1 h-10 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-white"
                />
              </div>
            </div>
            <button
              type="submit"
              className="rounded-lg bg-sky-500 px-5 py-2.5 text-xs font-black text-slate-950 hover:bg-sky-400"
            >
              Simpan Hot-Swap Config
            </button>
          </form>
        )}

        {tab === 'content' && (
          <div className="grid gap-6 md:grid-cols-2">
            <form onSubmit={submitMove} className="space-y-4 rounded-xl border border-white/10 bg-white/5 p-5">
              <h2 className="text-base font-bold text-white">Move Episode ke Seri Anime Lain (§8.2)</h2>
              <p className="text-xs text-slate-400">
                ID episode tetap utuh sehingga riwayat tontonan tidak rusak.
              </p>
              <input
                required
                placeholder="Episode ID (mis. 1024)"
                value={moveForm.data.episode_id}
                onChange={(e) => moveForm.setData('episode_id', e.target.value)}
                className="h-10 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-white"
              />
              <input
                required
                placeholder="Target Anime ID tujuan (mis. 56)"
                value={moveForm.data.target_anime_id}
                onChange={(e) => moveForm.setData('target_anime_id', e.target.value)}
                className="h-10 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-white"
              />
              <input
                placeholder="Nomor episode baru (opsional, kosongkan jika tetap)"
                value={moveForm.data.new_number}
                onChange={(e) => moveForm.setData('new_number', e.target.value)}
                className="h-10 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-white"
              />
              <button
                type="submit"
                className="rounded-lg bg-sky-500 px-4 py-2 text-xs font-black text-slate-950 hover:bg-sky-400"
              >
                Pindahkan Episode
              </button>
            </form>

            <form onSubmit={submitMerge} className="space-y-4 rounded-xl border border-white/10 bg-white/5 p-5">
              <h2 className="text-base font-bold text-white">Merge Anime Duplikat / Tombstone (§8.1)</h2>
              <p className="text-xs text-slate-400">
                Seluruh episode dipindahkan ke anime target, dan anime sumber dijadikan tombstone.
              </p>
              <input
                required
                placeholder="Source Anime ID (yang akan digabung/disembunyikan)"
                value={mergeForm.data.source_anime_id}
                onChange={(e) => mergeForm.setData('source_anime_id', e.target.value)}
                className="h-10 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-white"
              />
              <input
                required
                placeholder="Target Anime ID utama"
                value={mergeForm.data.target_anime_id}
                onChange={(e) => mergeForm.setData('target_anime_id', e.target.value)}
                className="h-10 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-white"
              />
              <button
                type="submit"
                className="rounded-lg bg-amber-500 px-4 py-2 text-xs font-black text-slate-950 hover:bg-amber-400"
              >
                Merge Anime Sekarang
              </button>
            </form>
          </div>
        )}

        {tab === 'users' && (
          <div className="grid gap-6 lg:grid-cols-3">
            <form onSubmit={submitUser} className="space-y-3 rounded-xl border border-white/10 bg-white/5 p-4">
              <h2 className="text-sm font-bold text-white">Buat Akun Pengguna Baru</h2>
              <input
                required
                placeholder="Nama lengkap"
                value={userForm.data.name}
                onChange={(e) => userForm.setData('name', e.target.value)}
                className="h-9 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-white"
              />
              <input
                required
                type="email"
                placeholder="Email"
                value={userForm.data.email}
                onChange={(e) => userForm.setData('email', e.target.value)}
                className="h-9 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-white"
              />
              <input
                required
                type="password"
                placeholder="Kata sandi (min. 8 karakter)"
                value={userForm.data.password}
                onChange={(e) => userForm.setData('password', e.target.value)}
                className="h-9 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-white"
              />
              <select
                value={userForm.data.role}
                onChange={(e) => userForm.setData('role', e.target.value)}
                className="h-9 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-xs text-white"
              >
                <option value="user">User</option>
                <option value="moderator">Moderator</option>
                <option value="admin">Admin</option>
                <option value="super_admin">Super Admin</option>
              </select>
              <button
                type="submit"
                className="w-full rounded-lg bg-sky-500 py-2 text-xs font-black text-slate-950 hover:bg-sky-400"
              >
                Buat Akun
              </button>
            </form>

            <div className="rounded-xl border border-white/10 bg-white/5 p-4 lg:col-span-2">
              <h2 className="text-sm font-bold text-white">Daftar Pengguna</h2>
              <div className="mt-3 space-y-2 text-xs">
                {users.map((u) => (
                  <div
                    key={u.id}
                    className="flex items-center justify-between rounded-lg border border-white/10 bg-slate-900 px-3 py-2"
                  >
                    <div>
                      <div className="font-bold text-white">
                        {u.name} <span className="text-sky-300">({u.role})</span>
                      </div>
                      <div className="text-slate-400">{u.email}</div>
                    </div>
                    <button
                      type="button"
                      onClick={() => router.post(`/admin/users/${u.id}/toggle-ban`)}
                      className={`rounded px-2.5 py-1 font-semibold ${
                        u.banned_at
                          ? 'bg-emerald-500/20 text-emerald-300'
                          : 'bg-rose-500/20 text-rose-300'
                      }`}
                    >
                      {u.banned_at ? 'Unban' : 'Ban'}
                    </button>
                  </div>
                ))}
              </div>
            </div>
          </div>
        )}

        {tab === 'audit' && (
          <div className="rounded-xl border border-white/10 bg-white/5 p-4">
            <h2 className="text-sm font-bold text-white">Audit Logs (IP & UA Ter-Hash SHA-256)</h2>
            <div className="mt-3 overflow-x-auto">
              <table className="min-w-full divide-y divide-white/10 text-xs">
                <thead>
                  <tr className="text-left text-slate-400">
                    <th className="py-2 pr-3">ID</th>
                    <th className="py-2 pr-3">Action</th>
                    <th className="py-2 pr-3">User ID</th>
                    <th className="py-2 pr-3">Target</th>
                    <th className="py-2">Waktu</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-white/5 text-slate-200">
                  {auditLogs.map((log) => (
                    <tr key={log.id}>
                      <td className="py-2 pr-3">#{log.id}</td>
                      <td className="py-2 pr-3 font-semibold text-sky-300">{log.action}</td>
                      <td className="py-2 pr-3">{log.user_id ?? '-'}</td>
                      <td className="py-2 pr-3">
                        {log.target_type ?? '-'} #{log.target_id ?? '-'}
                      </td>
                      <td className="py-2">{log.created_at}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </main>
    </>
  );
}
