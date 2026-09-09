<?php

namespace App\Providers;

use App\Contracts\Authenticator;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\TripTemplate;
use App\Models\User;
use App\Models\Vehicle;
use App\Policies\OwnedRecordPolicy;
use App\Services\LocalAuthenticator;
use App\Services\Settings;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->bind(Authenticator::class, LocalAuthenticator::class);
    }

    public function boot(): void
    {
        foreach ([Trip::class, Vehicle::class, TripTemplate::class, Settlement::class] as $model) {
            Gate::policy($model, OwnedRecordPolicy::class);
        }
        Gate::define('admin', fn (User $u) => $u->active && $u->isAdmin());
        Paginator::defaultView('pagination');
        Carbon::setLocale('de');
        if (is_file(storage_path('app/private/application.key')) && ! config('app.key')) {
            config(['app.key' => trim(file_get_contents(storage_path('app/private/application.key')))]);
        }
    }
}
