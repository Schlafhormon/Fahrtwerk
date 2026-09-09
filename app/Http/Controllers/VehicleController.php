<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Vehicle;
use App\Services\Audit;
use App\Services\Decimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class VehicleController extends Controller
{
    public function index(Request $r)
    {
        $q = Vehicle::with('user');
        if (! $r->user()->isAdmin()) {
            $q->where('user_id', $r->user()->id);
        }if ($r->filled('q')) {
            $q->where(fn ($s) => $s->where('name', 'like', '%'.substr($r->input('q'), 0, 100).'%')->orWhere('plate', 'like', '%'.substr($r->input('q'), 0, 100).'%'));
        }

        return view('vehicles.index', ['vehicles' => $q->orderByDesc('active')->orderBy('name')->paginate(25)->withQueryString()]);
    }

    public function create(Request $r)
    {
        return view('vehicles.form', ['vehicle' => new Vehicle(['energy' => 'Benzin', 'type' => 'Privatfahrzeug', 'active' => true]), 'people' => $r->user()->isAdmin() ? User::where('active', true)->orderBy('last_name')->get() : collect()]);
    }

    public function edit(Vehicle $vehicle)
    {
        Gate::authorize('view', $vehicle);

        return view('vehicles.form', ['vehicle' => $vehicle, 'people' => collect()]);
    }

    public function store(Request $r)
    {
        return $this->save($r, new Vehicle);
    }

    public function update(Request $r, Vehicle $vehicle)
    {
        Gate::authorize('update', $vehicle);

        return $this->save($r, $vehicle);
    }

    private function save(Request $r, Vehicle $vehicle)
    {
        $d = $r->validate(['name' => 'required|string|max:150', 'plate' => 'required|string|max:30', 'vin' => 'nullable|string|max:100', 'registration_number' => 'nullable|string|max:100', 'energy' => 'required|in:Benzin,Diesel,Hybrid,Plug-in-Hybrid,Elektro,Sonstige', 'type' => 'required|in:Privatfahrzeug,Dienstfahrzeug,Poolfahrzeug', 'initial_odometer' => 'nullable|numeric|min:0|max:99999999|decimal:0,2', 'user_id' => 'nullable|integer|exists:users,id']);
        DB::transaction(function () use ($r, $vehicle, $d) {
            $owner = $vehicle->user_id ?? ($r->user()->isAdmin() ? ($d['user_id'] ?? $r->user()->id) : $r->user()->id);
            User::whereKey($owner)->lockForUpdate()->firstOrFail();
            $vehicle = $vehicle->exists ? Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail() : $vehicle;
            $before = $vehicle->getAttributes();
            unset($d['user_id']);
            $default = $r->boolean('is_default') && $r->boolean('active');
            if ($default) {
                foreach (Vehicle::where('user_id', $owner)->where('is_default', true)->get() as $old) {
                    $b = $old->getAttributes();
                    $old->update(['is_default' => false]);
                    app(Audit::class)->record($old, 'default_changed', $b);
                }
            }if ($vehicle->exists && $vehicle->trips()->whereNotIn('status', ['DRAFT', 'RETURNED'])->exists()) {
                foreach (['name', 'plate', 'vin', 'registration_number', 'energy', 'type', 'initial_odometer'] as $field) {
                    $current = (string) ($vehicle->$field ?? '');
                    $incoming = (string) ($d[$field] ?? '');
                    if ($field === 'initial_odometer' && $current !== '' && $incoming !== '') {
                        $current = Decimal::format(Decimal::scaled($current));
                        $incoming = Decimal::format(Decimal::scaled($incoming));
                    }if ($incoming !== $current) {
                        throw ValidationException::withMessages([$field => 'Dieses Fahrzeug wird in eingereichten oder abgeschlossenen Fahrten verwendet. Stammdaten bleiben erhalten; bitte bei Bedarf ein neues Fahrzeug anlegen.']);
                    }
                }
            }$vehicle->fill($d + ['user_id' => $owner, 'active' => $r->boolean('active'), 'is_default' => $default]);
            $vehicle->save();
            app(Audit::class)->record($vehicle, $before ? 'update' : 'create', $before);
        });

        return redirect()->route('vehicles.index')->with('success', 'Fahrzeug gespeichert.');
    }
}
