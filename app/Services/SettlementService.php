<?php

namespace App\Services;

use App\Models\Period;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettlementService
{
    public function submit(User $user, Period $period): Settlement
    {
        return DB::transaction(function () use ($user, $period) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $period = Period::whereKey($period->id)->lockForUpdate()->firstOrFail();
            abort_unless($period->active, 403);
            if (! $user->profileComplete()) {
                throw ValidationException::withMessages(['profile' => 'Bitte vervollständigen Sie Ihr Profil.']);
            }
            $settlement = Settlement::firstOrCreate(['user_id' => $user->id, 'period_id' => $period->id], ['status' => 'OPEN']);
            abort_unless(in_array($settlement->status, ['OPEN', 'RETURNED']), 403);
            $trips = Trip::where('user_id', $user->id)->where('starts_at', '>=', $period->starts_on->startOfDay())->where('starts_at', '<=', $period->ends_on->endOfDay())->orderBy('starts_at')->lockForUpdate()->get();
            if ($trips->isEmpty()) {
                throw ValidationException::withMessages(['trips' => 'Dieser Zeitraum enthält keine Fahrten.']);
            }
            foreach ($trips as $trip) {
                abort_unless($trip->editable(), 403);
                app(TripValidator::class)->validate($trip->getAttributes(), $trip->vehicle, $trip, true);
                $before = $trip->getAttributes();
                $trip->update(['settlement_id' => $settlement->id, 'status' => 'SUBMITTED', 'submitted_at' => now()]);
                app(Audit::class)->record($trip, 'submit', $before, null, true);
            }
            $before = $settlement->getAttributes();
            $settlement->update(['status' => 'SUBMITTED', 'submitted_at' => now(), 'return_reason' => null]);
            app(Audit::class)->record($settlement, 'submit', $before, null, true);

            return $settlement;
        });
    }

    public function transition(User $actor, Settlement $settlement, string $target, ?string $reason = null): void
    {
        abort_unless($actor->isAdmin(), 403);
        DB::transaction(function () use ($actor, $settlement, $target, $reason) {
            User::whereKey($settlement->user_id)->lockForUpdate()->firstOrFail();
            app(Settings::class)->lock();
            $s = Settlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            $allowed = ['SUBMITTED' => ['RETURNED', 'APPROVED'], 'APPROVED' => ['SETTLED'], 'SETTLED' => ['LOCKED']];
            if (! in_array($target, $allowed[$s->status] ?? [])) {
                throw ValidationException::withMessages(['status' => 'Dieser Statuswechsel ist nicht zulässig.']);
            }
            if ($target === 'RETURNED' && ! trim($reason ?? '')) {
                throw ValidationException::withMessages(['reason' => 'Bitte geben Sie einen Rückgabegrund an.']);
            }
            $before = $s->getAttributes();
            $changes = ['status' => $target];
            $trips = $s->trips()->with('vehicle')->orderBy('starts_at')->lockForUpdate()->get();
            if ($target === 'APPROVED') {
                $total = 0;
                foreach ($trips as $trip) {
                    app(TripValidator::class)->validate($trip->getAttributes(), $trip->vehicle, $trip, true);
                    foreach (app(RateService::class)->positions($trip, true) as $position) {
                        $total += Decimal::scaled($position['amount']);
                        SettlementLine::create($position + ['settlement_id' => $s->id, 'trip_id' => $trip->id, 'trip_snapshot' => $trip->toArray(), 'calculated_at' => now()]);
                    }
                }
                $u = $s->user;
                $changes += ['approved_at' => now(), 'total' => Decimal::format($total), 'identity_snapshot' => ['employee' => $u->only(['username', 'first_name', 'last_name', 'address', 'personnel_number', 'occupation']), 'organization_unit' => $u->organizationUnit?->name, 'organization' => app(Settings::class)->get('organization_name'), 'address' => app(Settings::class)->get('organization_address'), 'period' => ['name' => $s->period->name, 'start' => $s->period->starts_on->toDateString(), 'end' => $s->period->ends_on->toDateString()], 'approved_by' => $actor->display_name]];
            }
            if ($target === 'RETURNED') {
                $changes['return_reason'] = $reason;
            }
            if ($target === 'SETTLED') {
                $changes['settled_at'] = now();
            }
            if ($target === 'LOCKED') {
                $changes['locked_at'] = now();
            }
            foreach ($trips as $trip) {
                $old = $trip->getAttributes();
                $attrs = ['status' => $target];
                if ($target === 'APPROVED') {
                    $attrs['approved_at'] = now();
                }if ($target === 'LOCKED') {
                    $attrs['locked_at'] = now();
                }$trip->update($attrs);
                app(Audit::class)->record($trip, 'status', $old, $reason, true);
            }
            $s->update($changes);
            app(Audit::class)->record($s, 'status', $before, $reason, true);
        });
    }
}
