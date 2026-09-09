<?php

namespace App\Http\Controllers;

use App\Models\TripTemplate;
use App\Models\Vehicle;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
        $d = $r->validate(['name' => 'required|string|max:150', 'route' => 'required|string|max:2000', 'destination' => 'required|string|max:255', 'purpose' => 'required|string|max:2000', 'vehicle_id' => 'nullable|integer']);
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
