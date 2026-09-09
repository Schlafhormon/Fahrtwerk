<?php

namespace App\Http\Controllers;

use App\Models\Period;
use App\Models\Settlement;
use App\Models\TripTemplate;
use App\Services\ReportService;
use App\Services\TripQuery;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $r)
    {
        $u = $r->user();
        $period = Period::where('starts_on', '<=', today())->where('ends_on', '>=', today())->first();
        $filters = ['from' => $period?->starts_on->toDateString() ?? today()->startOfMonth()->toDateString(), 'to' => $period?->ends_on->toDateString() ?? today()->endOfMonth()->toDateString()];
        $summary = app(ReportService::class)->build($u, $filters);
        $recent = app(TripQuery::class)->query($u)->orderByDesc('created_at')->limit(6)->get();
        $drafts = app(TripQuery::class)->query($u)->where('status', 'DRAFT')->count();
        $sq = Settlement::query();
        if (! $u->isAdmin()) {
            $sq->where('user_id', $u->id);
        }$returned = (clone $sq)->where('status', 'RETURNED')->with('period')->limit(5)->get();
        $pending = (clone $sq)->where('status', 'SUBMITTED')->count();
        $favorites = TripTemplate::where('user_id', $u->id)->orderByDesc('favorite')->orderByDesc('usage_count')->limit(4)->get();

        return view('dashboard', compact('period', 'summary', 'recent', 'drafts', 'returned', 'pending', 'favorites'));
    }
}
