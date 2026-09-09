<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->name(), 'username' => 'user_'.Str::lower(Str::random(12)), 'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'address' => fake()->address(), 'phone' => fake()->phoneNumber(), 'occupation' => 'Sachbearbeitung', 'password' => Str::password(20), 'role' => 'EMPLOYEE', 'active' => true, 'force_password_change' => false, 'logbook_enabled' => true];
    }
}
