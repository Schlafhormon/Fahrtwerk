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
        $template = null;
        if ($r->filled('template')) {
            $t = TripTemplate::findOrFail($r->input('template'));
            Gate::authorize('view', $t);
            abort_unless($t->user_id === $r->user()->id, 404);
            $template = $t;
            $trip->fill($t->only(['origin', 'route', 'destination', 'purpose']));
            if ($t->vehicle_id) {
                $trip->vehicle_id = $t->vehicle_id;
            }
            $trip->business_km = null;
        }

        return $this->form($r, $trip, $template);
    }

    public function edit(Request $r, Trip $trip)
    {
        Gate::authorize('view', $trip);

        return $this->form($r, $trip);
    }

    private function form(Request $r, Trip $trip, ?TripTemplate $template = null)
    {
        $vehicles = Vehicle::where('user_id', $trip->user_id ?? $r->user()->id)->where(fn ($q) => $q->where('active', true)->orWhere('id', $trip->vehicle_id))->orderByDesc('is_default')->get();
        $history = $trip->exists ? AuditEvent::where('record_type', 'Trip')->where('record_id', $trip->id)->orderByDesc('id')->paginate(30) : null;

        $canEdit = $trip->editable() && (! $trip->pair_id || ! $trip->pairedTrips()->where('id', '!=', $trip->id)->whereNotIn('status', ['DRAFT', 'RETURNED'])->exists());

        return view($template ? 'trips.quick' : 'trips.form', compact('trip', 'vehicles', 'history', 'template', 'canEdit'));
    }

    private function input(Request $r, string $prefix = '', array $defaults = []): array
    {
        $rules = [];
        foreach (['date' => 'nullable|date_format:Y-m-d', 'end_date' => 'nullable|date_format:Y-m-d', 'start_time' => 'nullable|date_format:H:i', 'end_time' => 'nullable|date_format:H:i', 'revision' => 'nullable|integer', 'kilometer_category' => 'nullable|in:business,commute,private'] as $field => $rule) {
            $rules[$prefix.$field] = $rule;
        }
        $rules[$prefix.'distance'] = ['nullable', 'numeric', 'min:0', 'max:99999999', 'regex:/^\d+(\.\d{1,2})?$/D'];
        $r->validate($rules);
        $data = $defaults;
        foreach (['date', 'end_date', 'start_time', 'end_time', 'origin', 'route', 'destination', 'purpose', 'odometer_start', 'odometer_end', 'business_km', 'commute_km', 'private_km', 'note', 'correction_reason', 'revision', 'distance', 'kilometer_category'] as $field) {
            if ($r->has($prefix.$field)) {
                $data[$field] = $r->input($prefix.$field);
            }
        }
        $data['vehicle_id'] = $r->input('vehicle_id');
        $data['starts_at'] = ! empty($data['date']) && ! empty($data['start_time']) ? $data['date'].' '.$data['start_time'].':00' : null;
        $data['ends_at'] = ! empty($data['date']) && ! empty($data['end_time']) ? ($data['end_date'] ?? $data['date']).' '.$data['end_time'].':00' : null;
        if (array_key_exists('distance', $data)) {
            $category = $data['kilometer_category'] ?? 'business';
            foreach (['business', 'commute', 'private'] as $kind) {
                $data[$kind.'_km'] = $kind === $category ? $data['distance'] : '0.00';
            }
        }
        if (empty($data['route']) && ! empty($data['origin']) && ! empty($data['destination'])) {
            $data['route'] = $data['origin'].' → '.$data['destination'];
        }

        return $data;
    }

    public function store(Request $r, TripService $service)
    {
        $r->validate(['vehicle_id' => 'required|integer', 'include_return' => 'sometimes|boolean', 'template_id' => 'nullable|integer']);
        $outbound = $this->input($r);
        $return = null;
        if ($r->boolean('include_return')) {
            $defaults = array_intersect_key($outbound, array_flip(['purpose', 'business_km', 'commute_km', 'private_km', 'date', 'distance', 'kilometer_category']));
            $defaults['origin'] = $outbound['destination'] ?? null;
            $defaults['destination'] = $outbound['origin'] ?? null;
            $return = $this->input($r, 'return_', $defaults);
        }
        $trip = $service->saveJourney($r->user(), $outbound, $return, $r->filled('template_id') ? (int) $r->input('template_id') : null);

        return redirect()->route('trips.edit', $trip)->with('success', $return ? 'Hin- und Rückfahrt als verknüpfte Entwürfe gespeichert.' : 'Fahrt als Entwurf gespeichert.');
    }

    public function update(Request $r, Trip $trip, TripService $service)
    {
        $r->validate(['vehicle_id' => 'required|integer']);
        $service->save($r->user(), $this->input($r), $trip);

        return back()->with('success', 'Fahrt gespeichert.');
    }

    public function destroy(Request $r, Trip $trip, TripService $service)
    {
        $service->delete($r->user(), $trip);

        return redirect()->route('trips.index')->with('success', 'Entwurf gelöscht.');
    }
}
