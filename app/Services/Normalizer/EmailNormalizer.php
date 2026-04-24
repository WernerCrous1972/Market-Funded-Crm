<?php

declare(strict_types=1);

namespace App\Services\Normalizer;

class EmailNormalizer
{
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function isValid(string $email): bool
    {
        return filter_var(self::normalize($email), FILTER_VALIDATE_EMAIL) !== false;
    }
}
