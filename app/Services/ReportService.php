<?php

namespace App\Services;

use App\Models\Period;
use App\Models\SettlementLine;
use App\Models\User;

class ReportService
{
    public function build(User $user, array $filters): array
    {
        $q = app(TripQuery::class)->query($user, $filters);
        $contextFilters = array_intersect_key($filters, array_flip(['user_id', 'organization_unit_id', 'from', 'to']));
        if (! empty($contextFilters['from'])) {
            $period = Period::where('starts_on', '<=', $contextFilters['from'])->where('ends_on', '>=', $contextFilters['from'])->first();
            $contextFilters['from'] = $period?->starts_on->toDateString() ?? $contextFilters['from'];
        }
        if (! empty($contextFilters['to'])) {
            $period = Period::where('starts_on', '<=', $contextFilters['to'])->where('ends_on', '>=', $contextFilters['to'])->first();
            $contextFilters['to'] = $period?->ends_on->toDateString() ?? $contextFilters['to'];
        }
        $pendingTrips = app(TripQuery::class)->query($user, $contextFilters)->whereIn('status', ['DRAFT', 'RETURNED', 'SUBMITTED'])->orderBy('id')->lazyById(250);
        $estimates = app(RateService::class)->positionsForTrips($pendingTrips);
        $result = ['count' => 0, 'total' => 0, 'business' => 0, 'commute' => 0, 'private' => 0, 'cost' => 0, 'unpriced' => 0, 'months' => [], 'years' => [], 'vehicles' => [], 'people' => [], 'units' => [], 'destinations' => [], 'purposes' => []];
        foreach ($q->orderBy('id')->lazyById(250) as $trip) {
            $result['count']++;
            $total = 0;
            foreach (['business', 'commute', 'private'] as $c) {
                $n = Decimal::scaled($trip->{$c.'_km'});
                $result[$c] += $n;
                $total += $n;
            }$result['total'] += $total;
            $lines = SettlementLine::where('trip_id', $trip->id)->get();
            if ($lines->isNotEmpty()) {
                $cost = Decimal::scaled(Decimal::sum($lines->pluck('amount')));
            } elseif ($trip->starts_at) {
                $positions = $estimates[$trip->id] ?? [];
                $cost = Decimal::scaled(Decimal::sum(array_column($positions, 'amount')));
                foreach ($positions as $p) {
                    if ($p['category'] === 'business' && ! $p['reimbursement_rule_id']) {
                        $result['unpriced']++;
                    }
                }
            } else {
                $cost = 0;
            }
            $result['cost'] += $cost;
            $groups = ['months' => $trip->starts_at?->format('Y-m') ?? 'Ohne Datum', 'years' => $trip->starts_at?->format('Y') ?? 'Ohne Datum', 'vehicles' => $trip->vehicle->name.' · '.$trip->vehicle->plate, 'people' => $trip->user->display_name.' ('.$trip->user->username.')', 'units' => $trip->user->organizationUnit?->name ?? 'Ohne Zuordnung', 'destinations' => $trip->destination ?: 'Ohne Ziel', 'purposes' => $trip->purpose ?: 'Ohne Zweck'];
            foreach ($groups as $group => $key) {
                $result[$group][$key] ??= ['km' => 0, 'cost' => 0, 'count' => 0];
                $result[$group][$key]['km'] += $total;
                $result[$group][$key]['cost'] += $cost;
                $result[$group][$key]['count']++;
            }
        }
        ksort($result['months']);
        ksort($result['years']);
        foreach (['vehicles', 'people', 'units', 'destinations', 'purposes'] as $key) {
            uasort($result[$key], fn ($a, $b) => $b['km'] <=> $a['km']);
        }

        return $result;
    }
}
