<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TripValidator
{
    public function validate(array $data, Vehicle $vehicle, ?Trip $trip = null, bool $complete = false): array
    {
        $required = $complete ? 'required' : 'nullable';
        $rules = ['starts_at' => [$required, 'date'], 'ends_at' => [$required, 'date', 'after_or_equal:starts_at'], 'route' => [$required, 'string', 'max:2000'], 'destination' => [$required, 'string', 'max:255'], 'purpose' => [$required, 'string', 'max:2000'], 'note' => ['nullable', 'string', 'max:4000'], 'correction_reason' => ['nullable', 'string', 'max:2000']];
        foreach (['odometer_start', 'odometer_end', 'business_km', 'commute_km', 'private_km'] as $f) {
            $rules[$f] = [$required, 'numeric', 'min:0', 'max:99999999', 'regex:/^\d+(\.\d{1,2})?$/D'];
        }
        $v = Validator::make($data, $rules);
        $v->validate();
        $error = [];
        $flags = [];
        $has = fn ($f) => isset($data[$f]) && $data[$f] !== '';
        if ($has('odometer_start') && $has('odometer_end')) {
            $diff = Decimal::scaled($data['odometer_end']) - Decimal::scaled($data['odometer_start']);
            if ($diff < 0) {
                $error['odometer_end'] = 'Der Endkilometerstand darf nicht kleiner als der Anfangsstand sein.';
            }
            if ($has('business_km') && $has('commute_km') && $has('private_km') && $diff !== Decimal::scaled($data['business_km']) + Decimal::scaled($data['commute_km']) + Decimal::scaled($data['private_km'])) {
                $error['business_km'] = 'Die drei Kilometerarten müssen zusammen genau der Kilometerstandsdifferenz entsprechen.';
            }
            if ($diff > 100000) {
                $flags[] = 'Ungewöhnlich lange Fahrt (mehr als 1.000 km)';
            }
        }
        foreach (['private', 'commute'] as $category) {
            if (! app(Settings::class)->get('allow_'.$category.'_km') && $has($category.'_km') && Decimal::scaled($data[$category.'_km']) > 0) {
                $error[$category.'_km'] = 'Diese Kilometerart ist derzeit deaktiviert.';
            }
        }
        if ($has('starts_at') && $has('ends_at')) {
            $overlap = Trip::where('id', '!=', $trip?->id ?? 0)->whereNotNull('starts_at')->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->where(fn ($q) => $q->where('user_id', $vehicle->user_id)->orWhere('vehicle_id', $vehicle->id))->exists();
            if ($overlap) {
                $flags[] = 'Zeitliche Überlappung';
                if ($complete) {
                    $error['starts_at'] = 'Diese Fahrt überschneidet sich mit einer anderen Fahrt der Person oder des Fahrzeugs.';
                }
            }
            $previous = Trip::where('vehicle_id', $vehicle->id)->where('id', '!=', $trip?->id ?? 0)->where('starts_at', '<', $data['starts_at'])->whereNotNull('odometer_end')->orderByDesc('starts_at')->first();
            $next = Trip::where('vehicle_id', $vehicle->id)->where('id', '!=', $trip?->id ?? 0)->where('starts_at', '>', $data['starts_at'])->whereNotNull('odometer_start')->orderBy('starts_at')->first();
            $expected = $previous?->odometer_end ?? $vehicle->initial_odometer;
            $jump = ($has('odometer_start') && $expected !== null && Decimal::scaled($data['odometer_start']) !== Decimal::scaled($expected)) || ($has('odometer_end') && $next && Decimal::scaled($data['odometer_end']) !== Decimal::scaled($next->odometer_start));
            if ($jump) {
                $flags[] = 'Kilometerstandsprung';
                if (app(Settings::class)->get('enforce_odometer_continuity') && empty($data['correction_reason'])) {
                    $error['correction_reason'] = 'Der Kilometerstand weicht vom vorherigen Endstand oder nachfolgenden Anfangsstand ab. Bitte korrigieren oder die Abweichung begründen.';
                }
            }
        }
        if (! $complete) {
            foreach (['starts_at', 'ends_at', 'route', 'destination', 'purpose', 'odometer_start', 'odometer_end', 'business_km', 'commute_km', 'private_km'] as $f) {
                if (! $has($f)) {
                    $flags[] = 'Fehlende Pflichtdaten';
                    break;
                }
            }
        }
        if ($error) {
            throw ValidationException::withMessages($error);
        }
        if ($trip && $trip->status === 'RETURNED') {
            $flags[] = 'Nachträglich korrigiert';
        }

        return array_merge($v->validated(), ['anomalies' => array_unique($flags)]);
    }
}
