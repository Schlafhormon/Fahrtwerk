<?php

namespace App\Services;

class Display
{
    public static function number(float|int|string|null $value, int $decimals = 2): string
    {
        $german = app(Settings::class)->get('number_format', 'de-DE') === 'de-DE';

        return number_format((float) $value, $decimals, $german ? ',' : '.', $german ? '.' : ',');
    }

    public static function date(?\DateTimeInterface $value, bool $time = false): string
    {
        return $value ? $value->format(app(Settings::class)->get('date_format', 'd.m.Y').($time ? ' H:i' : '')) : '–';
    }
}
