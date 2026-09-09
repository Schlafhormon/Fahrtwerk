<?php

namespace App\Services;

use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TripService
{
    public function save(User $actor, array $data, ?Trip $trip = null): Trip
    {
        return DB::transaction(function () use ($actor, $data, $trip) {
            if ($trip) {
                Gate::forUser($actor)->authorize('update', $trip);
            }
            $owner = User::whereKey($trip?->user_id ?? $actor->id)->lockForUpdate()->firstOrFail();
            if ($trip) {
                $trip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
                abort_unless($trip->editable(), 403, 'Diese Fahrt ist gesperrt.');
                if ((int) ($data['revision'] ?? 0) !== $trip->revision) {
                    throw ValidationException::withMessages(['revision' => 'Die Fahrt wurde inzwischen geändert. Bitte neu laden.']);
                }
            }
            abort_unless($owner->active && $owner->logbook_enabled, 403, 'Das Fahrtenbuch ist deaktiviert.');
            if (! $owner->profileComplete()) {
                throw ValidationException::withMessages(['profile' => 'Bitte zuerst das Mitarbeiterprofil vervollständigen.']);
            }
            $vehicle = Vehicle::where('user_id', $owner->id)->where('active', true)->findOrFail($data['vehicle_id'] ?? 0);
            if ($trip?->status === 'RETURNED' && empty($data['correction_reason'])) {
                throw ValidationException::withMessages(['correction_reason' => 'Bitte begründen Sie die Korrektur.']);
            }
            $validated = app(TripValidator::class)->validate($data, $vehicle, $trip);
            if (! empty($validated['starts_at'])) {
                $date = substr($validated['starts_at'], 0, 10);
                $closed = Settlement::where('user_id', $owner->id)->whereNotIn('status', ['OPEN', 'RETURNED'])->whereHas('period', fn ($q) => $q->where('starts_on', '<=', $date)->where('ends_on', '>=', $date))->exists();
                if ($closed) {
                    throw ValidationException::withMessages(['starts_at' => 'Dieser Zeitraum wurde bereits eingereicht.']);
                }
                if ($trip?->settlement_id) {
                    $p = $trip->settlement->period;
                    if ($date < $p->starts_on->toDateString() || $date > $p->ends_on->toDateString()) {
                        throw ValidationException::withMessages(['starts_at' => 'Eine zurückgegebene Fahrt muss im Abrechnungszeitraum bleiben.']);
                    }
                }
            }
            $before = $trip?->getAttributes() ?? [];
            $trip ??= new Trip(['user_id' => $owner->id, 'created_by' => $actor->id, 'status' => 'DRAFT']);
            $trip->fill($validated + ['vehicle_id' => $vehicle->id]);
            $trip->revision = ($trip->exists ? $trip->revision + 1 : 1);
            $trip->save();
            app(Audit::class)->record($trip, $before ? 'update' : 'create', $before, $data['correction_reason'] ?? null, $trip->status !== 'DRAFT');

            return $trip;
        });
    }

    public function delete(User $actor, Trip $trip): void
    {
        Gate::forUser($actor)->authorize('delete', $trip);
        DB::transaction(function () use ($trip) {
            User::whereKey($trip->user_id)->lockForUpdate()->firstOrFail();
            $t = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            abort_unless($t->status === 'DRAFT', 403);
            app(Audit::class)->record($t, 'delete', $t->getAttributes());
            $t->delete();
        });
    }
}
