<?php

namespace App\Http\Controllers;

use App\Models\Settlement;
use App\Services\ReportService;
use App\Services\TripQuery;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class ReportController extends Controller
{
    public function index(Request $r, TripQuery $query, ReportService $reports)
    {
        $filters = $query->filters($r);
        $summary = $reports->build($r->user(), $filters);
        $compare = null;
        if ($r->filled('compare_from') && $r->filled('compare_to')) {
            $d = $r->validate(['compare_from' => 'date_format:Y-m-d', 'compare_to' => 'date_format:Y-m-d|after_or_equal:compare_from']);
            $compare = $reports->build($r->user(), array_replace($filters, ['from' => $d['compare_from'], 'to' => $d['compare_to']]));
        }$sq = Settlement::query();
        if (! $r->user()->isAdmin()) {
            $sq->where('user_id', $r->user()->id);
        }$statuses = $sq->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');

        return view('reports.index', compact('filters', 'summary', 'compare', 'statuses'));
    }

    public function anomalies(Request $r, TripQuery $query)
    {
        $filters = $query->filters($r);
        $q = $query->query($r->user(), $filters)->where(fn ($s) => $s->whereJsonLength('anomalies', '>', 0)->orWhere('revision', '>', 1));

        return view('reports.anomalies', ['trips' => $query->sorted($q, $filters)->paginate(25)->withQueryString(), 'filters' => $filters]);
    }

    public function export(Request $r, TripQuery $query)
    {
        $filters = $query->filters($r);
        $format = $r->validate(['format' => 'required|in:csv,xlsx,print'])['format'];
        $q = $query->sorted($query->query($r->user(), $filters), $filters);
        if ($format === 'print') {
            return view('exports.trips', ['trips' => $q->cursor(), 'filters' => $filters]);
        }$headers = ['ID', 'Mitarbeiter', 'Kennzeichen', 'Fahrtbeginn', 'Fahrtende', 'Route', 'Ziel', 'Zweck', 'Km Beginn', 'Km Ende', 'Geschäftlich km', 'Arbeitsweg km', 'Privat km', 'Status'];
        $row = fn ($t) => array_map(fn ($v) => (string) ($v ?? ''), [$t->id, $t->user->display_name, $t->vehicle->plate, $t->starts_at?->format('d.m.Y H:i'), $t->ends_at?->format('d.m.Y H:i'), $t->route, $t->destination, $t->purpose, $t->odometer_start, $t->odometer_end, $t->business_km, $t->commute_km, $t->private_km, __('status.'.$t->status)]);
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($q, $headers, $row) {
                $f = fopen('php://output', 'w');
                fwrite($f, "\xEF\xBB\xBF");
                fputcsv($f, $headers, ';', '"', '');
                foreach ($q->cursor() as $trip) {
                    $values = array_map(fn ($v) => preg_match('/^[\s]*[=+@-]/u', $v) ? "'".$v : $v, $row($trip));
                    fputcsv($f, $values, ';', '"', '');
                }fclose($f);
            }, 'fahrten.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $path = tempnam(storage_path('app/private'), 'xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers));
        foreach ($q->cursor() as $trip) {
            // Explicit text cells prevent user-controlled '=' values becoming spreadsheet formulas.
            $writer->addRow(new Row(array_map(fn ($value) => new StringCell($value), $row($trip))));
        }$writer->close();

        return response()->download($path, 'fahrten.xlsx')->deleteFileAfterSend(true);
    }
}
