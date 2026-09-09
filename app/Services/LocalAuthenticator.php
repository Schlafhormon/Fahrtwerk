<?php

namespace App\Services;

use App\Contracts\Authenticator;
use Illuminate\Support\Facades\Auth;

class LocalAuthenticator implements Authenticator
{
    public function attempt(string $username, string $password): bool
    {
        return Auth::attempt(['username' => $username, 'password' => $password, 'active' => true]);
    }
}
