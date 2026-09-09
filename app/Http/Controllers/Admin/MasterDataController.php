<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationUnit;
use App\Models\Period;
use App\Models\ReimbursementRule;
use App\Services\Audit;
use App\Services\Settings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MasterDataController extends Controller
{
    public function index()
    {
        return view('admin.master', ['units' => OrganizationUnit::orderBy('name')->get(), 'rules' => ReimbursementRule::orderByDesc('id')->paginate(20, ['*'], 'rule_page'), 'periods' => Period::orderByDesc('starts_on')->paginate(12, ['*'], 'period_page')]);
    }

    public function unit(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:150', 'id' => 'nullable|exists:organization_units,id']);
        DB::transaction(function () use ($d, $r) {
            $u = isset($d['id']) ? OrganizationUnit::findOrFail($d['id']) : new OrganizationUnit;
            $b = $u->getAttributes();
            $u->fill(['name' => $d['name'], 'active' => $r->boolean('active')])->save();
            app(Audit::class)->record($u, 'saved', $b);
        });

        return back()->with('success', 'Organisationseinheit gespeichert.');
    }

    public function rule(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:150', 'category' => 'required|in:business,commute,private', 'vehicle_type' => 'nullable|in:Privatfahrzeug,Dienstfahrzeug,Poolfahrzeug', 'rate' => 'required|numeric|min:0|max:100|decimal:0,4', 'valid_from' => 'required|date_format:Y-m-d', 'valid_until' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from', 'description' => 'nullable|string|max:2000', 'predecessor_id' => 'nullable|exists:reimbursement_rules,id']);
        DB::transaction(function () use ($r, $d) {
            app(Settings::class)->lock();
            $prev = ! empty($d['predecessor_id']) ? ReimbursementRule::findOrFail($d['predecessor_id']) : null;
            if ($prev && ReimbursementRule::where('family_id', $prev->family_id)->where('version', '>', $prev->version)->exists()) {
                throw ValidationException::withMessages(['predecessor_id' => 'Bitte die neueste Regelversion auswählen.']);
            }unset($d['predecessor_id']);
            $rule = ReimbursementRule::create($d + ['family_id' => $prev?->family_id ?? (string) Str::uuid(), 'version' => ($prev?->version ?? 0) + 1, 'active' => $r->boolean('active'), 'created_by' => $r->user()->id, 'created_at' => now()]);
            app(Audit::class)->record($rule, 'rule_version_created', [], null, true);
        });

        return back()->with('success', 'Neue Regelversion gespeichert. Bereits freigegebene Abrechnungen bleiben unverändert.');
    }

    public function period(Request $r)
    {
        $d = $r->validate(['name' => 'nullable|string|max:150', 'starts_on' => 'nullable|required_without:month|date_format:Y-m-d', 'ends_on' => 'nullable|required_without:month|date_format:Y-m-d|after_or_equal:starts_on', 'month' => 'nullable|date_format:Y-m']);
        if (! empty($d['month'])) {
            $date = Carbon::createFromFormat('!Y-m', $d['month']);
            if ($r->boolean('use_default') && app(Settings::class)->get('default_period') === 'quarterly') {
                $d = ['name' => 'Quartal '.$date->quarter.' / '.$date->year, 'starts_on' => $date->copy()->startOfQuarter()->toDateString(), 'ends_on' => $date->copy()->endOfQuarter()->toDateString()];
            } else {
                $d = ['name' => $date->translatedFormat('F Y'), 'starts_on' => $date->copy()->startOfMonth()->toDateString(), 'ends_on' => $date->copy()->endOfMonth()->toDateString()];
            }
        }DB::transaction(function () use ($d) {
            app(Settings::class)->lock();
            if (Period::where('starts_on', '<=', $d['ends_on'])->where('ends_on', '>=', $d['starts_on'])->exists()) {
                throw ValidationException::withMessages(['starts_on' => 'Abrechnungszeiträume dürfen sich nicht überschneiden.']);
            }$p = Period::create(['name' => $d['name'] ?: $d['starts_on'].' – '.$d['ends_on'], 'starts_on' => $d['starts_on'], 'ends_on' => $d['ends_on'], 'active' => true]);
            app(Audit::class)->record($p, 'created');
        });

        return back()->with('success', 'Abrechnungszeitraum erstellt.');
    }
}
