<?php

namespace App\Http\Controllers;

use App\Contracts\Authenticator;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function form()
    {
        return view('auth.login');
    }

    public function login(Request $r, Authenticator $auth)
    {
        $d = $r->validate(['username' => 'required|string|max:100', 'password' => 'required|string|max:200']);
        $key = 'login:'.hash('sha256', mb_strtolower($d['username']).'|'.$r->ip());
        $ipKey = 'login-ip:'.hash('sha256', $r->ip());
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 50)) {
            throw ValidationException::withMessages(['username' => 'Zu viele Anmeldeversuche. Bitte versuchen Sie es in einer Minute erneut.']);
        }
        if (! $auth->attempt($d['username'], $d['password'])) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($ipKey, 60);
            throw ValidationException::withMessages(['username' => 'Benutzername oder Passwort ist ungültig.']);
        }
        RateLimiter::clear($key);
        $r->session()->regenerate();
        $r->session()->put('auth_version', $r->user()->auth_version);

        return redirect()->intended('/');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/anmelden');
    }

    public function passwordForm()
    {
        return view('auth.password');
    }

    public function password(Request $r)
    {
        $d = $r->validate(['current_password' => 'required|string', 'password' => 'required|string|min:8|max:128|confirmed']);
        $u = DB::transaction(function () use ($r, $d) {
            $u = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            if (! $u->active || $u->auth_version !== $r->user()->auth_version || ! Hash::check($d['current_password'], $u->password)) {
                throw ValidationException::withMessages(['current_password' => 'Das aktuelle Passwort oder die Sitzung ist nicht mehr gültig. Bitte erneut anmelden.']);
            }$u->update(['password' => $d['password'], 'force_password_change' => false, 'auth_version' => $u->auth_version + 1]);
            app(Audit::class)->record($u, 'password_changed', [], null, true);

            return $u;
        });
        Auth::setUser($u);
        $r->session()->regenerate();
        $r->session()->put('auth_version', $u->auth_version);

        return redirect('/')->with('success', 'Ihr Passwort wurde geändert.');
    }
}
