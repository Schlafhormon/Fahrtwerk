<?php

namespace App\Http\Controllers;

use App\Models\TripTemplate;
use App\Models\Vehicle;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TemplateController extends Controller
{
    public function index(Request $r)
    {
        return view('templates.index', ['templates' => TripTemplate::where('user_id', $r->user()->id)->orderByDesc('favorite')->orderBy('name')->paginate(25)]);
    }

    public function create(Request $r)
    {
        return $this->form($r, new TripTemplate);
    }

    public function edit(Request $r, TripTemplate $template)
    {
        Gate::authorize('view', $template);

        return $this->form($r, $template);
    }

    private function form(Request $r, TripTemplate $template)
    {
        return view('templates.form', ['template' => $template, 'vehicles' => Vehicle::where('user_id', $template->user_id ?? $r->user()->id)->where('active', true)->get()]);
    }

    public function store(Request $r)
    {
        return $this->save($r, new TripTemplate(['user_id' => $r->user()->id]));
    }

    public function update(Request $r, TripTemplate $template)
    {
        Gate::authorize('update', $template);

        return $this->save($r, $template);
    }

    private function save(Request $r, TripTemplate $t)
    {
        $distanceRules = ['nullable', 'numeric', 'min:0', 'max:99999999', 'regex:/^\d+(\.\d{1,2})?$/D'];
        $d = $r->validate(['name' => 'required|string|max:150', 'origin' => 'nullable|string|max:255', 'route' => 'nullable|string|max:2000', 'destination' => 'required|string|max:255', 'purpose' => 'required|string|max:2000', 'vehicle_id' => 'nullable|integer', 'distance' => $distanceRules, 'return_distance' => $distanceRules, 'kilometer_category' => 'sometimes|required|in:business,commute,private', 'include_return' => 'sometimes|boolean']);
        $d['route'] = $d['route'] ?? (! empty($d['origin']) ? $d['origin'].' → '.$d['destination'] : null);
        if (! $d['route']) {
            throw ValidationException::withMessages(['origin' => 'Bitte einen Start oder eine Reiseroute angeben.']);
        }
        $d['kilometer_category'] = $d['kilometer_category'] ?? 'business';
        $d['include_return'] = $r->boolean('include_return');
        $d['return_distance'] = $d['return_distance'] ?? ($d['distance'] ?? null);
        if (in_array($d['kilometer_category'], ['commute', 'private']) && ! app(Settings::class)->get('allow_'.$d['kilometer_category'].'_km')) {
            throw ValidationException::withMessages(['kilometer_category' => 'Diese Kilometerart ist derzeit deaktiviert.']);
        }
        if (! empty($d['vehicle_id'])) {
            Vehicle::where('user_id', $t->user_id)->where('active', true)->findOrFail($d['vehicle_id']);
        }DB::transaction(function () use ($r, $t, $d) {
            $b = $t->getAttributes();
            $t->fill($d + ['favorite' => $r->boolean('favorite')])->save();
            app(Audit::class)->record($t, 'saved', $b);
        });

        return redirect()->route('templates.index')->with('success', 'Vorlage gespeichert.');
    }

    public function destroy(TripTemplate $template)
    {
        Gate::authorize('delete', $template);
        DB::transaction(function () use ($template) {
            app(Audit::class)->record($template, 'delete', $template->getAttributes());
            $template->delete();
        });

        return back()->with('success', 'Vorlage gelöscht.');
    }
}
