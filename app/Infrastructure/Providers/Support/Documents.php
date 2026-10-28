<?php

namespace App\Infrastructure\Providers\Support;

final class Documents
{
    public static function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value);
    }

    public static function isCnpj(?string $document): bool
    {
        return strlen(self::digits($document)) === 14;
    }
}
