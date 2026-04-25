<?php

declare(strict_types=1);

use App\Services\Transaction\CategoryClassifier;

describe('CategoryClassifier', function () {

    // ── External deposits ────────────────────────────────────────────────────

    it('classifies a card deposit with no offer as EXTERNAL_DEPOSIT', function () {
        expect(CategoryClassifier::classify('DEPOSIT', 'DONE', 'Visa/Mastercard', null))
            ->toBe('EXTERNAL_DEPOSIT');
    });

    it('classifies a USDT deposit with no offer as EXTERNAL_DEPOSIT', function () {
        expect(CategoryClassifier::classify('DEPOSIT', 'DONE', 'USDT', null))
            ->toBe('EXTERNAL_DEPOSIT');
    });

    // ── External withdrawals ─────────────────────────────────────────────────

    it('classifies a USDT withdrawal as EXTERNAL_WITHDRAWAL', function () {
        expect(CategoryClassifier::classify('WITHDRAWAL', 'DONE', 'USDT', null))
            ->toBe('EXTERNAL_WITHDRAWAL');
    });

    it('classifies a card withdrawal as EXTERNAL_WITHDRAWAL', function () {
        expect(CategoryClassifier::classify('WITHDRAWAL', 'DONE', 'Visa/Mastercard', null))
            ->toBe('EXTERNAL_WITHDRAWAL');
    });

    // ── Our challenge purchases — TTR brand ──────────────────────────────────

    it('classifies TTR Evaluation offer + Internal Transfer as CHALLENGE_PURCHASE', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Internal Transfer', 'Evaluation_1_$5k TTR 3-Phase Challenge'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    it('classifies TTR challenge paid by card as CHALLENGE_PURCHASE (offer name wins over gateway)', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Visa/Mastercard', 'Evaluation_1_$5k TTR 3-Phase Challenge'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    it('classifies TTR challenge paid by USDT as CHALLENGE_PURCHASE', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'USDT', 'Instant Funded $10k TTR Plan'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    // ── Our challenge purchases — MFU brand ──────────────────────────────────

    it('classifies MFU Instant Funded offer + Internal Transfer as CHALLENGE_PURCHASE', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Internal Transfer', 'Instant Funded $10k MFU Plan'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    it('classifies MFU challenge paid by card as CHALLENGE_PURCHASE', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Visa/Mastercard', 'Verification Phase $25k MFU'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    it('classifies MFU Consistency offer as CHALLENGE_PURCHASE', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Internal Transfer', 'Consistency Challenge $15k MFU'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    it('classifies MFU Verification offer as CHALLENGE_PURCHASE', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Internal Transfer', 'Verification Phase $25k TTR'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    it('challenge keyword matching on offer name is case-insensitive', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Internal Transfer', 'EVALUATION_1_$5k TTR challenge'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    // ── Affiliate brand challenges — must NOT be CHALLENGE_PURCHASE ──────────

    it('classifies ATY Evaluation offer paid by card as EXTERNAL_DEPOSIT (affiliate brand)', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Visa/Mastercard', 'Evaluation_1_$5k ATY 3-Phase Challenge'
        ))->toBe('EXTERNAL_DEPOSIT');
    });

    it('classifies SOT Evaluation + Internal Transfer as INTERNAL_TRANSFER (affiliate, falls through)', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Internal Transfer', 'Evaluation_1_$5k SOT 3-Phase Challenge'
        ))->toBe('INTERNAL_TRANSFER');
    });

    it('classifies EAR Instant Funded + card as EXTERNAL_DEPOSIT (affiliate brand)', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Visa/Mastercard', 'Instant Funded $10k EAR Plan'
        ))->toBe('EXTERNAL_DEPOSIT');
    });

    it('classifies challenge offer with no brand code + Internal Transfer as INTERNAL_TRANSFER', function () {
        // No brand code — falls through to gateway check
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Internal Transfer', 'Evaluation $5k 3-Phase Challenge'
        ))->toBe('INTERNAL_TRANSFER');
    });

    it('classifies challenge offer with no brand code + card as EXTERNAL_DEPOSIT', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Visa/Mastercard', 'Evaluation $5k 3-Phase Challenge'
        ))->toBe('EXTERNAL_DEPOSIT');
    });

    // ── Brand code word-boundary tests ───────────────────────────────────────

    it('MATI brand does not match MFU (substring is not a whole word)', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Visa/Mastercard', 'Evaluation_1_$5k MATI 3-Phase Challenge'
        ))->toBe('EXTERNAL_DEPOSIT');
    });

    it('brand code matching is case-sensitive — lowercase ttr does not match TTR', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Visa/Mastercard', 'Evaluation_1_$5k ttr 3-Phase Challenge'
        ))->toBe('EXTERNAL_DEPOSIT');
    });

    it('brand code matches at start of offer name', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Internal Transfer', 'TTR Evaluation $5k'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    it('brand code matches at end of offer name', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Internal Transfer', 'Evaluation $5k TTR'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    // ── Pre-31 March 2026 — historical ambiguity ─────────────────────────────

    it('classifies pre-changeover challenge purchase (no offer name) as INTERNAL_TRANSFER', function () {
        // Gateway = Internal Transfer, no offer name — indistinguishable from real transfer
        expect(CategoryClassifier::classify('DEPOSIT', 'DONE', 'Internal Transfer', null))
            ->toBe('INTERNAL_TRANSFER');
    });

    // ── Real internal transfers ──────────────────────────────────────────────

    it('classifies a real internal transfer (deposit side, no offer) as INTERNAL_TRANSFER', function () {
        expect(CategoryClassifier::classify('DEPOSIT', 'DONE', 'Internal Transfer', null))
            ->toBe('INTERNAL_TRANSFER');
    });

    it('classifies a real internal transfer (withdrawal side) as INTERNAL_TRANSFER', function () {
        expect(CategoryClassifier::classify('WITHDRAWAL', 'DONE', 'Internal Transfer', null))
            ->toBe('INTERNAL_TRANSFER');
    });

    // ── Challenge refunds ────────────────────────────────────────────────────

    it('classifies TurboTrade Challenge withdrawal as CHALLENGE_REFUND', function () {
        expect(CategoryClassifier::classify('WITHDRAWAL', 'DONE', 'TurboTrade Challenge', null))
            ->toBe('CHALLENGE_REFUND');
    });

    it('TurboTrade Challenge gateway matching is case-insensitive', function () {
        expect(CategoryClassifier::classify('WITHDRAWAL', 'DONE', 'turbotrade challenge', null))
            ->toBe('CHALLENGE_REFUND');
    });

    // ── Non-DONE status → UNCLASSIFIED ───────────────────────────────────────

    it('classifies PENDING deposit as UNCLASSIFIED', function () {
        expect(CategoryClassifier::classify('DEPOSIT', 'PENDING', 'Visa/Mastercard', null))
            ->toBe('UNCLASSIFIED');
    });

    it('classifies FAILED withdrawal as UNCLASSIFIED', function () {
        expect(CategoryClassifier::classify('WITHDRAWAL', 'FAILED', 'USDT', null))
            ->toBe('UNCLASSIFIED');
    });

    it('classifies REVERSED transaction as UNCLASSIFIED', function () {
        expect(CategoryClassifier::classify('DEPOSIT', 'REVERSED', 'USDT', null))
            ->toBe('UNCLASSIFIED');
    });
});
