<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserService
{
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $password = Str::password(20, symbols: false);
            $u = User::create($data + ['password' => $password, 'force_password_change' => true, 'name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''))]);
            app(Audit::class)->record($u, 'created');

            return [$u, $password];
        });
    }

    public function reset(User $user): string
    {
        return DB::transaction(function () use ($user) {
            $u = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $password = Str::password(20, symbols: false);
            $u->update(['password' => $password, 'force_password_change' => true, 'auth_version' => $u->auth_version + 1, 'remember_token' => null]);
            DB::table('sessions')->where('user_id', $u->id)->delete();
            app(Audit::class)->record($u, 'password_reset', [], null, true);

            return $password;
        });
    }
}
