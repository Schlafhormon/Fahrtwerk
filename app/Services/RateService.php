<?php

namespace App\Services;

use App\Models\Period;
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

    /** @return list<array{category: string, kilometers: string, billable_kilometers: string, kilometer_rounding: string, rate: string, amount: string, reimbursement_rule_id: ?int}> */
    private function positions(Trip $trip, bool $strict = false): array
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
            $rounding = $rule?->kilometer_rounding ?? 'exact';
            $billableKilometers = $rounding === 'per_trip' ? Decimal::format(intdiv(Decimal::scaled($km), 100) * 100) : $km;
            $items[] = ['category' => $category, 'kilometers' => $km, 'billable_kilometers' => $billableKilometers, 'kilometer_rounding' => $rounding, 'rate' => $rule?->rate ?? '0.0000', 'amount' => Decimal::amount($billableKilometers, $rule?->rate ?? '0.0000'), 'reimbursement_rule_id' => $rule?->id];
        }

        return $items;
    }

    /**
     * Aggregate rounding groups trips by employee, period and rule version.
     * Deduct fractional kilometers from the last positions and allocate cents
     * cumulatively so the positions add up to the rounded group amount.
     *
     * @param  iterable<Trip>  $trips
     * @return array<int, list<array{category: string, kilometers: string, billable_kilometers: string, kilometer_rounding: string, rate: string, amount: string, reimbursement_rule_id: ?int}>>
     */
    public function positionsForTrips(iterable $trips, bool $strict = false): array
    {
        $periods = Period::orderBy('starts_on')->get();
        $positions = [];
        $groups = [];
        $orderedTrips = collect($trips)->sort(fn (Trip $left, Trip $right): int => [$left->starts_at?->timestamp ?? 0, $left->id] <=> [$right->starts_at?->timestamp ?? 0, $right->id]);
        foreach ($orderedTrips as $trip) {
            if (! $trip->starts_at) {
                continue;
            }
            $positions[$trip->id] = $this->positions($trip, $strict);
            $date = $trip->starts_at->toDateString();
            $period = $periods->first(fn (Period $period): bool => $period->starts_on->toDateString() <= $date && $period->ends_on->toDateString() >= $date);
            foreach ($positions[$trip->id] as $index => $position) {
                if ($position['kilometer_rounding'] !== 'aggregate') {
                    continue;
                }
                $scope = $period ? 'period:'.$period->id : 'trip:'.$trip->id;
                $key = $trip->user_id.':'.$scope.':'.$position['reimbursement_rule_id'].':'.$position['category'];
                $groups[$key] ??= ['kilometers' => 0, 'members' => []];
                $groups[$key]['kilometers'] += Decimal::scaled($position['kilometers']);
                $groups[$key]['members'][] = [$trip->id, $index];
            }
        }
        foreach ($groups as $group) {
            $remainingDeduction = $group['kilometers'] % 100;
            foreach (array_reverse($group['members']) as [$tripId, $index]) {
                $kilometers = Decimal::scaled($positions[$tripId][$index]['kilometers']);
                $deduction = min($remainingDeduction, $kilometers);
                $positions[$tripId][$index]['billable_kilometers'] = Decimal::format($kilometers - $deduction);
                $remainingDeduction -= $deduction;
            }
            $cumulativeKilometers = 0;
            $cumulativeAmount = 0;
            foreach ($group['members'] as [$tripId, $index]) {
                $position = $positions[$tripId][$index];
                $cumulativeKilometers += Decimal::scaled($position['billable_kilometers']);
                $amount = Decimal::scaled(Decimal::amount(Decimal::format($cumulativeKilometers), $position['rate']));
                $positions[$tripId][$index]['amount'] = Decimal::format($amount - $cumulativeAmount);
                $cumulativeAmount = $amount;
            }
        }

        return $positions;
    }
}
