<?php

declare(strict_types=1);

use App\Services\Pipeline\Classifier;

describe('Pipeline Classifier', function () {
    it('classifies an offer containing "challenge" as MFU_CAPITAL', function () {
        expect(Classifier::classify('30-Day Challenge'))->toBe('MFU_CAPITAL');
    });

    it('classifies an offer containing "evaluation" as MFU_CAPITAL', function () {
        expect(Classifier::classify('Standard Evaluation Account'))->toBe('MFU_CAPITAL');
    });

    it('classifies an offer containing "funded" as MFU_CAPITAL', function () {
        expect(Classifier::classify('Instant Funded Pro'))->toBe('MFU_CAPITAL');
    });

    it('classifies an offer containing "academy" as MFU_ACADEMY', function () {
        expect(Classifier::classify('IB Academy Starter'))->toBe('MFU_ACADEMY');
    });

    it('classifies an offer containing "course" as MFU_ACADEMY', function () {
        expect(Classifier::classify('Advanced Trading Course'))->toBe('MFU_ACADEMY');
    });

    it('classifies a live trading offer as MFU_MARKETS by default', function () {
        expect(Classifier::classify('Live Standard Account'))->toBe('MFU_MARKETS');
    });

    it('classifies a null offer name as UNCLASSIFIED', function () {
        expect(Classifier::classify(null))->toBe('UNCLASSIFIED');
    });

    it('classifies by prop offer UUID when UUID matches', function () {
        Classifier::setPropOfferUuids(['PROP-UUID-001']);
        expect(Classifier::classify('Some Offer', 'PROP-UUID-001'))->toBe('MFU_CAPITAL');
    });

    it('UUID match is case-insensitive', function () {
        Classifier::setPropOfferUuids(['prop-uuid-002']);
        expect(Classifier::classify('Some Offer', 'PROP-UUID-002'))->toBe('MFU_CAPITAL');
    });

    it('classifies keyword checks case-insensitively', function () {
        expect(Classifier::classify('PHASE ONE ACCOUNT'))->toBe('MFU_CAPITAL');
    });
});
