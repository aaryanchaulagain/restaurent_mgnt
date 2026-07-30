<?php

namespace App\Support;

final class Abn
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return preg_replace('/\D+/', '', $value) ?: null;
    }

    public static function format(?string $value): ?string
    {
        $digits = self::normalize($value);
        if ($digits === null || strlen($digits) !== 11) {
            return $value;
        }

        return substr($digits, 0, 2).' '
            .substr($digits, 2, 3).' '
            .substr($digits, 5, 3).' '
            .substr($digits, 8, 3);
    }

    public static function isValid(?string $value): bool
    {
        $digits = self::normalize($value);
        if ($digits === null || strlen($digits) !== 11) {
            return false;
        }

        $weights = [10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19];
        $sum = 0;
        for ($i = 0; $i < 11; $i++) {
            $digit = (int) $digits[$i];
            if ($i === 0) {
                $digit -= 1;
            }
            $sum += $digit * $weights[$i];
        }

        return $sum % 89 === 0;
    }
}
