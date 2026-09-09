<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function edit(Request $r)
    {
        return view('profile', ['person' => $r->user()]);
    }

    public function update(Request $r)
    {
        $d = $r->validate(['first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100', 'address' => 'required|string|max:1000', 'phone' => 'required|string|max:50', 'occupation' => 'required|string|max:150']);
        DB::transaction(function () use ($r, $d) {
            $u = $r->user();
            $before = $u->getAttributes();
            $u->update($d + ['name' => $d['first_name'].' '.$d['last_name']]);
            app(Audit::class)->record($u, 'profile_updated', $before);
        });

        return redirect('/profil')->with('success', 'Profil gespeichert.');
    }
}
