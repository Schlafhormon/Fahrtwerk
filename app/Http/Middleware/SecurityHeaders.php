<?php

namespace App\Http\Middleware;

use Closure;

class SecurityHeaders
{
    public function handle($r, Closure $next)
    {
        $response = $next($r);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
        if ($r->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }if (! $r->is('up', 'health')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
