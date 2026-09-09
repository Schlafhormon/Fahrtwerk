<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\VehicleController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    DB::select('SELECT 1');
    abort_unless(is_writable(storage_path('framework/views')), 503);

    return response()->json(['status' => 'ok']);
});
Route::middleware('guest')->group(function () {
    Route::get('/anmelden', [AuthController::class, 'form'])->name('login');
    Route::post('/anmelden', [AuthController::class, 'login']);
});
Route::middleware(['auth', 'ready'])->group(function () {
    Route::post('/abmelden', [AuthController::class, 'logout'])->name('logout');
    Route::get('/passwort', [AuthController::class, 'passwordForm'])->name('password.edit');
    Route::post('/passwort', [AuthController::class, 'password'])->name('password.update');
    Route::get('/profil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profil', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/logo', [SettingsController::class, 'logo'])->name('logo');
    Route::resource('/fahrten', TripController::class)->names('trips')->parameters(['fahrten' => 'trip'])->except('show');
    Route::resource('/fahrzeuge', VehicleController::class)->names('vehicles')->parameters(['fahrzeuge' => 'vehicle'])->except(['show', 'destroy']);
    Route::resource('/vorlagen', TemplateController::class)->names('templates')->parameters(['vorlagen' => 'template'])->except('show');
    Route::get('/abrechnungen', [SettlementController::class, 'index'])->name('settlements.index');
    Route::get('/zeitraeume/{period}', [SettlementController::class, 'period'])->name('periods.show');
    Route::post('/zeitraeume/{period}/einreichen', [SettlementController::class, 'submit'])->name('periods.submit');
    Route::get('/abrechnungen/{settlement}', [SettlementController::class, 'show'])->name('settlements.show');
    Route::get('/abrechnungen/{settlement}/pdf', [SettlementController::class, 'pdf'])->name('settlements.pdf');
    Route::post('/abrechnungen/{settlement}/status', [SettlementController::class, 'transition'])->middleware('admin')->name('settlements.transition');
    Route::get('/auswertungen', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/export', [ReportController::class, 'export'])->name('reports.export');
    Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
        Route::resource('/mitarbeitende', UserController::class)->names('people')->parameters(['mitarbeitende' => 'person'])->except(['show', 'destroy']);
        Route::post('/mitarbeitende/{person}/passwort', [UserController::class, 'reset'])->name('people.reset');
        Route::get('/stammdaten', [MasterDataController::class, 'index'])->name('master');
        Route::post('/organisationseinheiten', [MasterDataController::class, 'unit'])->name('units.store');
        Route::post('/regeln', [MasterDataController::class, 'rule'])->name('rules.store');
        Route::post('/zeitraeume', [MasterDataController::class, 'period'])->name('periods.store');
        Route::get('/einstellungen', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/einstellungen', [SettingsController::class, 'update'])->name('settings.update');
        Route::get('/audit', [AuditController::class, 'index'])->name('audit');
        Route::get('/auffaelligkeiten', [ReportController::class, 'anomalies'])->name('anomalies');
        Route::get('/import', [ImportController::class, 'index'])->name('import.index');
        Route::post('/import/datei', [ImportController::class, 'upload'])->name('import.upload');
        Route::post('/import/vorschau', [ImportController::class, 'preview'])->name('import.preview');
        Route::post('/import/ausfuehren', [ImportController::class, 'commit'])->name('import.commit');
    });
});
