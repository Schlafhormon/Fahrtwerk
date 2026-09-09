<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\Audit;
use App\Services\Settings;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $r)
    {
        $q = User::with('organizationUnit');
        if ($r->filled('q')) {
            $term = '%'.substr($r->input('q'), 0, 100).'%';
            $q->where(fn ($s) => $s->where('username', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('first_name', 'like', $term));
        }

        return view('admin.users.index', ['people' => $q->orderByDesc('active')->orderBy('last_name')->paginate(25)->withQueryString()]);
    }

    public function create()
    {
        return $this->form(new User(['active' => true, 'logbook_enabled' => true, 'role' => 'EMPLOYEE']));
    }

    public function edit(User $person)
    {
        return $this->form($person);
    }

    private function form(User $person)
    {
        return view('admin.users.form', ['person' => $person, 'units' => OrganizationUnit::orderBy('name')->get()]);
    }

    private function data(Request $r, ?User $u = null): array
    {
        return $r->validate(['username' => ['required', 'regex:/^[a-zA-Z0-9._-]{3,80}$/D', Rule::unique('users')->ignore($u?->id)], 'first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100', 'address' => 'nullable|string|max:1000', 'phone' => 'nullable|string|max:50', 'occupation' => 'nullable|string|max:150', 'personnel_number' => 'nullable|string|max:100', 'organization_unit_id' => 'nullable|exists:organization_units,id', 'role' => 'required|in:EMPLOYEE,ADMIN']) + ['active' => $r->boolean('active'), 'logbook_enabled' => $r->boolean('logbook_enabled')];
    }

    public function store(Request $r, UserService $s)
    {
        [$person,$password] = $s->create($this->data($r));

        return response()->view('admin.users.credential', compact('person', 'password'));
    }

    public function update(Request $r, User $person)
    {
        $d = $this->data($r, $person);
        DB::transaction(function () use ($r, $person, $d) {
            app(Settings::class)->lock();
            $person = User::whereKey($person->id)->lockForUpdate()->firstOrFail();
            if ($person->id === $r->user()->id && (! $d['active'] || $d['role'] !== 'ADMIN')) {
                throw ValidationException::withMessages(['active' => 'Sie können Ihren eigenen Administrationszugang nicht deaktivieren oder herabstufen.']);
            }if ($person->isAdmin() && $person->active && (! $d['active'] || $d['role'] !== 'ADMIN') && User::where('role', 'ADMIN')->where('active', true)->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'Mindestens ein aktiver Administrator muss erhalten bleiben.']);
            }$b = $person->getAttributes();
            $person->update($d + ['name' => $d['first_name'].' '.$d['last_name'], 'auth_version' => $person->auth_version + 1]);
            app(Audit::class)->record($person, 'updated', $b, null, true);
        });

        return redirect()->route('admin.people.index')->with('success', 'Mitarbeiterdaten gespeichert.');
    }

    public function reset(User $person, UserService $s)
    {
        $password = $s->reset($person);

        return response()->view('admin.users.credential', compact('person', 'password'));
    }
}
