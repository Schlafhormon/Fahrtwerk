<?php

use App\Http\Middleware\EnsureAccountReady;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $m): void {
        $m->alias(['ready' => EnsureAccountReady::class, 'admin' => EnsureAdmin::class]);
        $m->append(SecurityHeaders::class);
        $m->redirectGuestsTo('/anmelden');
    })
    ->withExceptions(function (Exceptions $e): void {
        $e->dontFlash(['current_password', 'password', 'password_confirmation', 'bootstrap_password']);
        // Never log exception messages, SQL bindings, request bodies or session data.
        $e->report(function (Throwable $exception) {
            Log::error('application_error', [
                'exception' => get_class($exception),
                'code' => (string) $exception->getCode(),
                'source' => basename($exception->getFile()),
                'line' => $exception->getLine(),
                'route' => app()->runningInConsole() ? 'console' : request()->route()?->getName(),
            ]);

            return false;
        });
        $e->shouldRenderJsonWhen(fn (Request $r) => $r->expectsJson());
    })->create();
