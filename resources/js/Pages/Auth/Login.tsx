import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = {
  openRegistration: boolean;
  isLocal: boolean;
};

export default function Login({ isLocal }: Props) {
  const { data, setData, post, processing, errors } = useForm({
    email: isLocal ? 'admin@zonaotaku.my.id' : '',
    password: isLocal ? 'AdminZonaotaku2026!' : '',
    remember: true,
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    post('/login');
  };

  return (
    <>
      <Head title="Masuk" />

      <main className="mx-auto flex min-h-[80vh] max-w-md items-center px-4 py-10">
        <div className="w-full rounded-2xl border border-white/10 bg-white/5 p-6 shadow-xl">
          <h1 className="text-2xl font-black text-white">Masuk ke Zonaotaku</h1>
          <p className="mt-1 text-xs text-slate-400">
            Platform streaming anime private. Registrasi publik ditutup secara default.
          </p>

          {isLocal && (
            <div className="mt-4 rounded-lg border border-sky-400/20 bg-sky-500/10 p-3 text-xs text-sky-200">
              <div className="font-bold">Akun Super Admin Lokal (Otomatis Terisi):</div>
              <div className="mt-1">
                Email: <code>admin@zonaotaku.my.id</code>
                <br />
                Sandi: <code>AdminZonaotaku2026!</code>
              </div>
            </div>
          )}

          <form onSubmit={submit} className="mt-5 space-y-4">
            <div>
              <label className="block text-xs font-semibold text-slate-300">Email</label>
              <input
                type="email"
                required
                value={data.email}
                onChange={(e) => setData('email', e.target.value)}
                className="mt-1.5 h-10 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-sm text-white outline-none focus:border-sky-400"
              />
              {errors.email && <p className="mt-1 text-xs text-rose-400">{errors.email}</p>}
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300">Kata Sandi</label>
              <input
                type="password"
                required
                value={data.password}
                onChange={(e) => setData('password', e.target.value)}
                className="mt-1.5 h-10 w-full rounded-lg border border-white/10 bg-slate-900 px-3 text-sm text-white outline-none focus:border-sky-400"
              />
              {errors.password && <p className="mt-1 text-xs text-rose-400">{errors.password}</p>}
            </div>

            <button
              type="submit"
              disabled={processing}
              className="h-11 w-full rounded-lg bg-sky-500 text-sm font-black text-slate-950 transition hover:bg-sky-400 disabled:opacity-40"
            >
              {processing ? 'MEMPROSES...' : 'MASUK'}
            </button>
          </form>
        </div>
      </main>
    </>
  );
}
