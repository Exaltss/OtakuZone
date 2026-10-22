<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\Security\AuditWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

final class LoginController extends Controller
{
    public function showLoginForm(Request $request): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('catalog.index');
        }

        $openReg = (bool) Setting::get(
            'security.open_registration',
            config('zonaotaku.security.open_registration', false)
        );

        return Inertia::render('Auth/Login', [
            'openRegistration' => $openReg,
            'isLocal' => app()->environment('local'),
        ]);
    }

    public function login(Request $request, AuditWriter $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || !Hash::check($credentials['password'], $user->password)) {
            $audit->write('auth.login_fail', $user?->id, 'User', $user?->id, [
                'email' => $credentials['email'],
            ], $request);

            return back()->withErrors([
                'email' => 'Email atau kata sandi tidak sesuai.',
            ]);
        }

        if ($user->isBanned()) {
            $audit->write('auth.login_fail', (int) $user->id, 'User', (int) $user->id, [
                'reason' => 'banned',
            ], $request);

            return back()->withErrors([
                'email' => 'Akun ini sedang dinonaktifkan oleh administrator.',
            ]);
        }

        Auth::login($user, $request->boolean('remember', true));
        $request->session()->regenerate();

        $user->last_login_at = now();
        $user->save();

        $audit->write('auth.login_ok', (int) $user->id, 'User', (int) $user->id, [
            'role' => $user->role,
        ], $request);

        return redirect()->intended($user->isAdmin() ? '/admin' : '/');
    }

    public function register(Request $request, AuditWriter $audit): RedirectResponse
    {
        $openReg = (bool) Setting::get(
            'security.open_registration',
            config('zonaotaku.security.open_registration', false)
        );

        if (!$openReg) {
            abort(404, 'Registrasi publik ditutup.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'user',
        ]);

        Auth::login($user, true);
        $request->session()->regenerate();

        $audit->write('auth.register_ok', (int) $user->id, 'User', (int) $user->id, [], $request);

        return redirect()->route('catalog.index')->with('success', 'Akun berhasil dibuat.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('catalog.index');
    }
}
