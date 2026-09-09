<?php

namespace App\Services;

use App\Models\Setting;

class Settings
{
    private array $cache = [];

    public function get(string $key, mixed $fallback = null): mixed
    {
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = Setting::find($key)?->value ?? config('fahrtwerk.'.$key, $fallback);
        }

        return $this->cache[$key];
    }

    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        $this->cache[$key] = $value;
    }

    public function lock(): void
    {
        Setting::where('key', 'domain_lock')->lockForUpdate()->firstOrFail();
    }
}
