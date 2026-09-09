<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Trip;
use App\Models\TripTemplate;
use App\Models\Vehicle;
use App\Services\Settings;
use App\Services\TripQuery;
use App\Services\TripService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TripController extends Controller
{
    public function index(Request $r, TripQuery $query)
    {
        $filters = $query->filters($r);
        $trips = $query->sorted($query->query($r->user(), $filters), $filters)->paginate(app(Settings::class)->get('page_size', 25))->withQueryString();

        return view('trips.index', compact('trips', 'filters'));
    }

    public function create(Request $r)
    {
        $trip = new Trip(['vehicle_id' => $r->user()->vehicles()->where('active', true)->orderByDesc('is_default')->value('id'), 'business_km' => '0.00', 'commute_km' => '0.00', 'private_km' => '0.00']);
        if ($r->filled('template')) {
            $t = TripTemplate::findOrFail($r->input('template'));
            Gate::authorize('view', $t);
            $trip->fill($t->only(['vehicle_id', 'route', 'destination', 'purpose']));
        }

        return $this->form($r, $trip);
    }

    public function edit(Request $r, Trip $trip)
    {
        Gate::authorize('view', $trip);

        return $this->form($r, $trip);
    }

    private function form(Request $r, Trip $trip)
    {
        $vehicles = Vehicle::where('user_id', $trip->user_id ?? $r->user()->id)->where(fn ($q) => $q->where('active', true)->orWhere('id', $trip->vehicle_id))->orderByDesc('is_default')->get();
        $history = $trip->exists ? AuditEvent::where('record_type', 'Trip')->where('record_id', $trip->id)->orderByDesc('id')->paginate(30) : null;

        return view('trips.form', compact('trip', 'vehicles', 'history'));
    }

    private function input(Request $r): array
    {
        $d = $r->validate(['date' => 'nullable|date_format:Y-m-d', 'end_date' => 'nullable|date_format:Y-m-d', 'start_time' => 'nullable|date_format:H:i', 'end_time' => 'nullable|date_format:H:i', 'vehicle_id' => 'required|integer', 'revision' => 'nullable|integer']);
        $data = $r->only(['route', 'destination', 'purpose', 'odometer_start', 'odometer_end', 'business_km', 'commute_km', 'private_km', 'note', 'correction_reason', 'vehicle_id', 'revision']);
        $data['starts_at'] = ! empty($d['date']) && ! empty($d['start_time']) ? $d['date'].' '.$d['start_time'].':00' : null;
        $data['ends_at'] = ! empty($d['date']) && ! empty($d['end_time']) ? ($d['end_date'] ?? $d['date']).' '.$d['end_time'].':00' : null;

        return $data;
    }

    public function store(Request $r, TripService $service)
    {
        $trip = $service->save($r->user(), $this->input($r));
        if ($r->filled('template_id')) {
            TripTemplate::where('user_id', $r->user()->id)->whereKey($r->input('template_id'))->increment('usage_count');
        }

        return redirect()->route('trips.edit', $trip)->with('success', 'Fahrt als Entwurf gespeichert.');
    }

    public function update(Request $r, Trip $trip, TripService $service)
    {
        $service->save($r->user(), $this->input($r), $trip);

        return back()->with('success', 'Fahrt gespeichert.');
    }

    public function destroy(Request $r, Trip $trip, TripService $service)
    {
        $service->delete($r->user(), $trip);

        return redirect()->route('trips.index')->with('success', 'Entwurf gelöscht.');
    }
}
