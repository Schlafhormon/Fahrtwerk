<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function edit()
    {
        return view('admin.settings');
    }

    public function update(Request $r, Settings $s)
    {
        $d = $r->validate(['organization_name' => 'required|string|max:150', 'organization_address' => 'nullable|string|max:1000', 'default_period' => 'required|in:monthly,quarterly,custom', 'page_size' => 'required|integer|min:10|max:100', 'retention_years' => 'required|integer|min:1|max:50', 'number_format' => 'required|in:de-DE,en-GB', 'date_format' => 'required|in:d.m.Y,Y-m-d', 'logo' => 'nullable|file|mimes:png,jpg,jpeg|max:1024|dimensions:max_width=2000,max_height=2000']);
        $logo = null;
        if ($r->hasFile('logo')) {
            $source = imagecreatefromstring(file_get_contents($r->file('logo')->getRealPath()));
            abort_unless($source, 422);
            ob_start();
            imagepng($source);
            $bytes = ob_get_clean();
            $logo = 'logos/'.bin2hex(random_bytes(16)).'.png';
            Storage::disk('local')->put($logo, $bytes);
            unset($d['logo']);
            $d['logo_path'] = $logo;
        }DB::transaction(function () use ($d, $r, $s) {
            $s->lock();
            foreach (['allow_private_km', 'allow_commute_km', 'enforce_odometer_continuity'] as $key) {
                $d[$key] = $r->boolean($key);
            }foreach ($d as $key => $value) {
                $old = Setting::find($key)?->getAttributes() ?? [];
                $s->set($key, $value);
                $record = Setting::find($key);
                app(Audit::class)->record($record, 'setting_changed', $old, $key, true);
            }
        });

        return back()->with('success', 'Einstellungen gespeichert.');
    }

    public function logo(Settings $s)
    {
        $path = $s->get('logo_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => 'image/png']);
    }
}
