<?php

declare(strict_types=1);

namespace App\Services\Normalizer;

class PhoneNormalizer
{
    // Test/placeholder numbers to skip
    private const SKIP_PATTERNS = [
        '/^0{7,}$/',
        '/^9{7,}$/',
        '/^1{7,}$/',
    ];

    // Country calling codes — ordered by specificity (longer prefixes first)
    private const CALLING_CODES = [
        '27'  => 'ZA',
        '1'   => 'US',
        '44'  => 'GB',
        '254' => 'KE',
        '233' => 'GH',
        '234' => 'NG',
        '255' => 'TZ',
        '256' => 'UG',
        '260' => 'ZM',
        '263' => 'ZW',
        '267' => 'BW',
        '264' => 'NA',
    ];

    /**
     * Normalize a raw phone string to E.164 format (e.g. +27681234567).
     * Returns null if the number is invalid or a test number.
     */
    public static function normalize(string $raw, string $defaultCountryCode = 'ZA'): ?string
    {
        // Strip everything except digits and leading +
        $digits = preg_replace('/[^\d+]/', '', trim($raw));

        if ($digits === null || $digits === '' || $digits === '+') {
            return null;
        }

        // Remove leading +
        $digits = ltrim($digits, '+');

        // Must have at least 7 digits
        if (strlen($digits) < 7) {
            return null;
        }

        // Check for test/placeholder numbers
        foreach (self::SKIP_PATTERNS as $pattern) {
            if (preg_match($pattern, $digits)) {
                return null;
            }
        }

        // South Africa specific: 0XX → +27XX
        if ($defaultCountryCode === 'ZA') {
            if (str_starts_with($digits, '0') && strlen($digits) === 10) {
                $digits = '27' . substr($digits, 1);
            } elseif (str_starts_with($digits, '27') && strlen($digits) === 11) {
                // Already has country code
            }
        }

        // If still looks like a local number (no country code), prepend default
        if (strlen($digits) <= 10 && ! self::hasKnownCallingCode($digits)) {
            $defaultDialing = match ($defaultCountryCode) {
                'ZA' => '27',
                'US' => '1',
                'GB' => '44',
                default => '27',
            };
            $digits = $defaultDialing . ltrim($digits, '0');
        }

        // Final check: must be 7–15 digits (E.164 range)
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }

        return '+' . $digits;
    }

    /**
     * Extract ISO-2 country code from an E.164 number.
     */
    public static function countryCode(string $e164): ?string
    {
        $digits = ltrim($e164, '+');

        // Try longest match first (3-digit codes before 1-digit)
        foreach (self::CALLING_CODES as $code => $iso2) {
            if (str_starts_with($digits, (string) $code)) {
                return $iso2;
            }
        }

        return null;
    }

    private static function hasKnownCallingCode(string $digits): bool
    {
        foreach (array_keys(self::CALLING_CODES) as $code) {
            if (str_starts_with($digits, (string) $code)) {
                return true;
            }
        }

        return false;
    }
}
