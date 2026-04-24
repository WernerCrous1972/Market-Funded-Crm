<?php

declare(strict_types=1);

use App\Services\Normalizer\PhoneNormalizer;

describe('PhoneNormalizer', function () {
    it('normalises a South African number starting with 0', function () {
        expect(PhoneNormalizer::normalize('0681234567', 'ZA'))->toBe('+27681234567');
    });

    it('normalises a number already prefixed with 27', function () {
        expect(PhoneNormalizer::normalize('27681234567', 'ZA'))->toBe('+27681234567');
    });

    it('normalises a number with leading +27', function () {
        expect(PhoneNormalizer::normalize('+27681234567'))->toBe('+27681234567');
    });

    it('returns null for a test number of all zeros', function () {
        expect(PhoneNormalizer::normalize('0000000000'))->toBeNull();
    });

    it('returns null for a test number of all nines', function () {
        expect(PhoneNormalizer::normalize('9999999999'))->toBeNull();
    });

    it('returns null for an empty string', function () {
        expect(PhoneNormalizer::normalize(''))->toBeNull();
    });

    it('returns null for a number that is too short', function () {
        expect(PhoneNormalizer::normalize('12345'))->toBeNull();
    });

    it('strips spaces and dashes', function () {
        expect(PhoneNormalizer::normalize('+27 68 123 4567'))->toBe('+27681234567');
    });

    it('extracts the ZA country code from an E.164 number', function () {
        expect(PhoneNormalizer::countryCode('+27681234567'))->toBe('ZA');
    });
});
