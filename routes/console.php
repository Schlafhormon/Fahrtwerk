<?php

use App\Models\Settlement;
use App\Models\User;
use App\Services\Settings;
use App\Services\UserService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('fahrtwerk:bootstrap', function () {
    if (config('fahrtwerk.initial_password_mode') !== 'random') {
        throw new RuntimeException('INITIAL_PASSWORD_MODE muss random sein.');
    }
    if (config('app.env') === 'production' && (config('app.debug') || (str_starts_with(config('app.url'), 'https://') && ! config('session.secure')))) {
        throw new RuntimeException('Unsichere Produktivkonfiguration: APP_DEBUG deaktivieren und für HTTPS Secure-Cookies aktivieren.');
    }
    $username = config('fahrtwerk.bootstrap_username');
    if (! $username) {
        $this->info('Bootstrap deaktiviert.');

        return;
    }
    DB::transaction(function () use ($username) {
        app(Settings::class)->lock();
        if (User::where('username', $username)->exists()) {
            return;
        }
        $password = config('fahrtwerk.bootstrap_password');
        if ($password !== null && $password !== '' && strlen($password) < 8) {
            throw new RuntimeException('Das Bootstrap-Passwort muss mindestens 8 Zeichen lang sein.');
        }
        [$user,$generated] = app(UserService::class)->create(['username' => $username, 'first_name' => 'Administration', 'last_name' => 'Fahrtwerk', 'role' => 'ADMIN', 'active' => true, 'logbook_enabled' => true]);
        if ($password) {
            $user->update(['password' => $password]);
        } else {
            Storage::disk('local')->put('bootstrap-admin.txt', 'Benutzername: '.$username."\nTemporäres Passwort: ".$generated."\nNach sicherer Übergabe diese Datei löschen.\n");
        }
    });
    $this->info('Bootstrap geprüft. Ein erzeugtes Einmalpasswort liegt ausschließlich in storage/app/private/bootstrap-admin.txt.');
})->purpose('Bootstrap-Administrator einmalig anlegen; keine Geheimnisse in Logs');
Artisan::command('fahrtwerk:admin-reset {username}', function () {
    $u = User::where('username', $this->argument('username'))->firstOrFail();
    $password = app(UserService::class)->reset($u);
    Storage::disk('local')->put('admin-reset.txt', 'Benutzername: '.$u->username."\nTemporäres Passwort: ".$password."\n");
    $this->info('Einmalzugang in storage/app/private/admin-reset.txt gespeichert. Nach Übergabe entfernen.');
})->purpose('Lokale Wiederherstellung eines Benutzerzugangs');
Artisan::command('fahrtwerk:retention-report', function () {
    $years = (int) app(Settings::class)->get('retention_years', 10);
    $q = Settlement::where('status', 'LOCKED')->where('locked_at', '<', now()->subYears($years));
    $this->info('Prüfkandidaten nach '.$years.' Jahren: '.$q->count());
    $q->orderBy('id')->chunkById(200, function ($rows) {
        foreach ($rows as $row) {
            $this->line('Abrechnung #'.$row->id.' / gesperrt '.$row->locked_at->toDateString());
        }
    });
    $this->info('Keine Daten gelöscht. Fachliche Freigabe und Archivierung sind gesondert erforderlich.');
})->purpose('Aufbewahrungsstrategie: Prüfliste ohne automatische historische Löschung');
Artisan::command('fahrtwerk:cleanup', function () {
    foreach (Storage::disk('local')->files('imports') as $path) {
        if (Storage::disk('local')->lastModified($path) < time() - 3600) {
            Storage::disk('local')->delete($path);
        }
    }DB::table('sessions')->where('last_activity', '<', time() - config('session.lifetime') * 60)->delete();
    $this->info('Abgelaufene Imports und Sitzungen bereinigt.');
})->purpose('Temporäre personenbezogene Daten entfernen');
Schedule::command('fahrtwerk:cleanup')->hourly();
Schedule::call(fn () => file_put_contents(storage_path('framework/scheduler-heartbeat'), (string) time()))->everyMinute()->evenInMaintenanceMode();
