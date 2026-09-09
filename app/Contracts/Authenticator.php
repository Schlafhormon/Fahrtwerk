<?php

namespace App\Contracts;

interface Authenticator
{
    public function attempt(string $username, string $password): bool;
}
