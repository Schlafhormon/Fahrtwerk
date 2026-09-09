<?php

namespace App\Http\Middleware;

use Closure;

class EnsureAdmin
{
    public function handle($r, Closure $next)
    {
        abort_unless($r->user()?->isAdmin() && $r->user()->active, 403);

        return $next($r);
    }
}
