<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureAccountReady
{
    public function handle(Request $r, Closure $next)
    {
        $u = $r->user();
        if (! $u->active || (int) $r->session()->get('auth_version', $u->auth_version) !== $u->auth_version) {
            Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return redirect('/anmelden');
        }
        if ($u->force_password_change && ! $r->routeIs('password.*', 'logout')) {
            return redirect()->route('password.edit');
        }
        if (! $u->profileComplete() && ! $r->routeIs('profile.*', 'password.*', 'logout')) {
            return redirect()->route('profile.edit')->with('notice', 'Bitte vervollständigen Sie zunächst Ihr Profil.');
        }

        return $next($r);
    }
}
