<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (config('fahrtwerk.demo_enabled') && app()->environment('local', 'testing')) {
            $this->call(DemoSeeder::class);
        }
    }
}
