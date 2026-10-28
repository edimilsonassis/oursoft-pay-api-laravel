<?php

namespace App\Infrastructure\Providers\Support;

final class Money
{
    /** 1234 → "12.34" (formato das APIs Pix). */
    public static function toDecimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** "12.34" | 12.34 → 1234 */
    public static function toCents(string|int|float|null $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
