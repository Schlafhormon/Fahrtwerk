<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $r)
    {
        $d = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'actor_id' => 'nullable|integer', 'record_type' => 'nullable|string|max:80', 'record_id' => 'nullable|string|max:255', 'action' => 'nullable|string|max:80']);
        $q = AuditEvent::with('actor');
        foreach (['actor_id', 'record_type', 'record_id', 'action'] as $f) {
            if (! empty($d[$f])) {
                $q->where($f, $d[$f]);
            }
        }if (! empty($d['from'])) {
            $q->where('created_at', '>=', $d['from']);
        }if (! empty($d['to'])) {
            $q->where('created_at', '<=', $d['to'].' 23:59:59');
        }

        return view('admin.audit', ['events' => $q->orderByDesc('id')->paginate(50)->withQueryString(), 'people' => User::orderBy('last_name')->get()]);
    }
}
