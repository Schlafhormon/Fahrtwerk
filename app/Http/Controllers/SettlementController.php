<?php

namespace App\Http\Controllers;

use App\Models\Period;
use App\Models\Settlement;
use App\Models\Trip;
use App\Services\SettlementService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SettlementController extends Controller
{
    public function index(Request $r)
    {
        $q = Settlement::with(['user', 'period']);
        if (! $r->user()->isAdmin()) {
            $q->where('user_id', $r->user()->id);
        }if (in_array($r->input('status'), ['OPEN', 'RETURNED', 'SUBMITTED', 'APPROVED', 'SETTLED', 'LOCKED'])) {
            $q->where('status', $r->input('status'));
        }

        return view('settlements.index', ['settlements' => $q->orderByDesc('updated_at')->paginate(25)->withQueryString(), 'periods' => Period::where('active', true)->orderByDesc('starts_on')->paginate(12, ['*'], 'period_page')]);
    }

    public function period(Request $r, Period $period)
    {
        $settlement = Settlement::where('user_id', $r->user()->id)->where('period_id', $period->id)->first();
        $trips = Trip::with('vehicle')->where('user_id', $r->user()->id)->whereBetween('starts_at', [$period->starts_on->startOfDay(), $period->ends_on->endOfDay()])->orderBy('starts_at')->paginate(50);

        return view('settlements.period', compact('period', 'settlement', 'trips'));
    }

    public function submit(Request $r, Period $period, SettlementService $service)
    {
        $s = $service->submit($r->user(), $period);

        return redirect()->route('settlements.show', $s)->with('success', 'Abrechnung eingereicht. Die Fahrten sind jetzt zur Prüfung gesperrt.');
    }

    public function show(Settlement $settlement)
    {
        Gate::authorize('view', $settlement);
        $settlement->load(['user', 'period']);
        $trips = $settlement->trips()->with('vehicle')->orderBy('starts_at')->paginate(50);

        return view('settlements.show', compact('settlement', 'trips'));
    }

    public function transition(Request $r, Settlement $settlement, SettlementService $service)
    {
        $d = $r->validate(['status' => 'required|in:RETURNED,APPROVED,SETTLED,LOCKED', 'reason' => 'nullable|string|max:2000']);
        $service->transition($r->user(), $settlement, $d['status'], $d['reason'] ?? null);

        return back()->with('success', 'Status aktualisiert.');
    }

    public function pdf(Settlement $settlement)
    {
        Gate::authorize('view', $settlement);
        abort_unless(in_array($settlement->status, ['APPROVED', 'SETTLED', 'LOCKED']), 403);
        $settlement->load('lines');
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('exports.settlement', ['settlement' => $settlement])->render());
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="abrechnung-'.$settlement->id.'.pdf"']);
    }
}
