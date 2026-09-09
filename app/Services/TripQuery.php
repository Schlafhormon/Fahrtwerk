<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Http\Request;

class TripQuery
{
    public function query(User $user, array $filters = [])
    {
        $q = Trip::query()->with(['user.organizationUnit', 'vehicle', 'settlement']);
        if (! $user->isAdmin()) {
            $q->where('user_id', $user->id);
        }
        if (! empty($filters['from'])) {
            $q->where('starts_at', '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $q->where('starts_at', '<=', $filters['to'].' 23:59:59');
        }
        if (! empty($filters['vehicle_id'])) {
            $q->where('vehicle_id', $filters['vehicle_id']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['settlement_status'])) {
            $q->whereHas('settlement', fn ($s) => $s->where('status', $filters['settlement_status']));
        }
        foreach (['destination', 'purpose'] as $field) {
            if (! empty($filters[$field])) {
                $q->where($field, 'like', '%'.$filters[$field].'%');
            }
        }
        if (in_array($filters['category'] ?? null, ['business', 'commute', 'private'])) {
            $q->where($filters['category'].'_km', '>', 0);
        }
        if (! empty($filters['q'])) {
            $q->where(fn ($s) => $s->where('destination', 'like', '%'.$filters['q'].'%')->orWhere('purpose', 'like', '%'.$filters['q'].'%')->orWhere('route', 'like', '%'.$filters['q'].'%'));
        }
        if ($user->isAdmin()) {
            if (! empty($filters['user_id'])) {
                $q->where('user_id', $filters['user_id']);
            }
            if (! empty($filters['organization_unit_id'])) {
                $q->whereHas('user', fn ($u) => $u->where('organization_unit_id', $filters['organization_unit_id']));
            }
        }

        return $q;
    }

    public function filters(Request $r): array
    {
        return $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'vehicle_id' => 'nullable|integer', 'user_id' => 'nullable|integer', 'organization_unit_id' => 'nullable|integer', 'status' => 'nullable|in:DRAFT,RETURNED,SUBMITTED,APPROVED,SETTLED,LOCKED', 'settlement_status' => 'nullable|in:OPEN,RETURNED,SUBMITTED,APPROVED,SETTLED,LOCKED', 'destination' => 'nullable|string|max:255', 'purpose' => 'nullable|string|max:255', 'category' => 'nullable|in:business,commute,private', 'q' => 'nullable|string|max:150', 'sort' => 'nullable|in:starts_at,destination,business_km,status', 'direction' => 'nullable|in:asc,desc']);
    }

    public function sorted($q, array $f)
    {
        return $q->orderBy($f['sort'] ?? 'starts_at', $f['direction'] ?? 'desc')->orderByDesc('id');
    }
}
