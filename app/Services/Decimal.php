<?php

namespace App\Services;

final class Decimal
{
    public static function scaled(string|int|null $value, int $scale = 2): int
    {
        $v = (string) ($value ?? '0');
        if (! preg_match('/^\d+(?:\.\d{1,'.$scale.'})?$/D', $v)) {
            throw new \InvalidArgumentException('Ungültige Dezimalzahl');
        }
        [$whole,$fraction] = array_pad(explode('.', $v, 2), 2, '');

        return (int) $whole * (10 ** $scale) + (int) str_pad($fraction, $scale, '0');
    }

    public static function format(int $value, int $scale = 2): string
    {
        return ($value < 0 ? '-' : '').intdiv(abs($value), 10 ** $scale).'.'.str_pad((string) (abs($value) % (10 ** $scale)), $scale, '0', STR_PAD_LEFT);
    }

    public static function amount(string $km, string $rate): string
    {
        return self::format(intdiv(self::scaled($km) * self::scaled($rate, 4) + 5000, 10000));
    }

    public static function sum(iterable $values): string
    {
        $v = 0;
        foreach ($values as $value) {
            $v += self::scaled($value);
        }

        return self::format($v);
    }
}
