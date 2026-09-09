<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImportController extends Controller
{
    public function index()
    {
        return view('admin.import', ['stage' => 'upload']);
    }

    public function upload(Request $r, ImportService $s)
    {
        $r->validate(['file' => 'required|file|max:2048|mimes:csv,txt', 'delimiter' => 'required|in:semicolon,comma']);
        $data = $s->parse($r->file('file')->getRealPath(), $r->input('delimiter') === 'semicolon' ? ';' : ',');
        $token = bin2hex(random_bytes(24));
        Storage::disk('local')->put('imports/'.$token.'.json', json_encode($data, JSON_THROW_ON_ERROR));
        $r->session()->put('import', ['token' => $token, 'expires' => time() + 1800, 'user_id' => $r->user()->id]);

        return view('admin.import', $data + ['stage' => 'mapping', 'fields' => ImportService::FIELDS]);
    }

    private function data(Request $r): array
    {
        $state = $r->session()->get('import');
        abort_unless($state && $state['user_id'] === $r->user()->id && $state['expires'] >= time(), 419, 'Importvorschau abgelaufen.');
        $path = 'imports/'.$state['token'].'.json';
        abort_unless(Storage::disk('local')->exists($path), 419);

        return json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function preview(Request $r, ImportService $s)
    {
        $data = $this->data($r);
        $d = $r->validate(['mapping' => 'required|array', 'mapping.*' => 'nullable|integer|min:0|max:39']);
        $result = $s->process($r->user(), $data['rows'], $d['mapping'], false);
        $state = $r->session()->get('import');
        $state['mapping'] = $d['mapping'];
        $state['valid'] = empty($result['errors']);
        $r->session()->put('import', $state);

        return view('admin.import', $data + ['rowErrors' => $result['errors'], 'count' => $result['count'], 'stage' => 'preview']);
    }

    public function commit(Request $r, ImportService $s)
    {
        $data = $this->data($r);
        $state = $r->session()->get('import');
        abort_unless($state['valid'] ?? false, 422);
        $result = $s->process($r->user(), $data['rows'], $state['mapping'], true);
        if ($result['errors']) {
            return view('admin.import', $data + ['rowErrors' => $result['errors'], 'count' => $result['count'], 'stage' => 'preview']);
        }Storage::disk('local')->delete('imports/'.$state['token'].'.json');
        $r->session()->forget('import');

        return redirect()->route('trips.index')->with('success', $result['count'].' historische Fahrten wurden atomar als prüfbare Entwürfe importiert.');
    }
}
