<?php

declare(strict_types=1);

use App\Services\Normalizer\EmailNormalizer;

describe('EmailNormalizer', function () {
    it('lowercases the email', function () {
        expect(EmailNormalizer::normalize('Werner@Market-Funded.COM'))->toBe('werner@market-funded.com');
    });

    it('trims whitespace', function () {
        expect(EmailNormalizer::normalize('  werner@market-funded.com  '))->toBe('werner@market-funded.com');
    });

    it('validates a valid email', function () {
        expect(EmailNormalizer::isValid('werner@market-funded.com'))->toBeTrue();
    });

    it('rejects an email with no @', function () {
        expect(EmailNormalizer::isValid('notanemail'))->toBeFalse();
    });

    it('rejects an empty string', function () {
        expect(EmailNormalizer::isValid(''))->toBeFalse();
    });
});
