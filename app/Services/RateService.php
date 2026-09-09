<?php

namespace App\Services;

use App\Models\ReimbursementRule;
use App\Models\Trip;
use Illuminate\Validation\ValidationException;

class RateService
{
    public function rule(Trip $trip, string $category): ?ReimbursementRule
    {
        $date = $trip->starts_at->toDateString();

        return ReimbursementRule::where('category', $category)->where('valid_from', '<=', $date)->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date))
            ->where(fn ($q) => $q->whereNull('vehicle_type')->orWhere('vehicle_type', $trip->vehicle->type))
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('reimbursement_rules as newer')->whereColumn('newer.family_id', 'reimbursement_rules.family_id')->whereColumn('newer.version', '>', 'reimbursement_rules.version');
            })
            ->where('active', true)->orderByRaw('CASE WHEN vehicle_type IS NULL THEN 1 ELSE 0 END')->orderByDesc('valid_from')->orderByDesc('id')->first();
    }

    public function positions(Trip $trip, bool $strict = false): array
    {
        $items = [];
        foreach (['business', 'commute', 'private'] as $category) {
            $km = $trip->{$category.'_km'} ?? '0.00';
            if (Decimal::scaled($km) === 0 && $category !== 'business') {
                continue;
            }$rule = $this->rule($trip, $category);
            if (! $rule && $strict && $category === 'business' && Decimal::scaled($km) > 0) {
                throw ValidationException::withMessages(['rule' => 'Für Fahrt #'.$trip->id.' fehlt ein gültiger geschäftlicher Erstattungssatz.']);
            }
            $items[] = ['category' => $category, 'kilometers' => $km, 'rate' => $rule?->rate ?? '0.0000', 'amount' => Decimal::amount($km, $rule?->rate ?? '0.0000'), 'reimbursement_rule_id' => $rule?->id];
        }

        return $items;
    }
}
