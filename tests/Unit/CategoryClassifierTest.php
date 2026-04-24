<?php

declare(strict_types=1);

use App\Services\Transaction\CategoryClassifier;

describe('CategoryClassifier', function () {

    // ── External deposits ────────────────────────────────────────────────────

    it('classifies a card deposit as EXTERNAL_DEPOSIT', function () {
        expect(CategoryClassifier::classify('DEPOSIT', 'DONE', 'Visa/Mastercard', null))
            ->toBe('EXTERNAL_DEPOSIT');
    });

    it('classifies a USDT deposit as EXTERNAL_DEPOSIT', function () {
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

    // ── Challenge purchases (post-31 March 2026 format) ──────────────────────

    it('classifies Evaluation offer + Internal Transfer as CHALLENGE_PURCHASE', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Internal Transfer', 'Evaluation_1_$5k TTR 3-Phase Challenge'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    it('classifies Instant Funded offer + Internal Transfer as CHALLENGE_PURCHASE', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Internal Transfer', 'Instant Funded $10k Plan'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    it('classifies Verification offer + Internal Transfer as CHALLENGE_PURCHASE', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Internal Transfer', 'Verification Phase $25k'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    it('classifies Consistency offer + Internal Transfer as CHALLENGE_PURCHASE', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Internal Transfer', 'Consistency Challenge $15k'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    it('keyword matching on offer name is case-insensitive', function () {
        expect(CategoryClassifier::classify(
            'DEPOSIT', 'DONE', 'Internal Transfer', 'EVALUATION_1_$5k ttr challenge'
        ))->toBe('CHALLENGE_PURCHASE');
    });

    // ── Pre-31 March 2026 — historical ambiguity ─────────────────────────────

    it('classifies pre-changeover challenge purchase as INTERNAL_TRANSFER (accepted ambiguity)', function () {
        // Gateway = Internal Transfer, no offer name — indistinguishable from a real transfer
        expect(CategoryClassifier::classify('DEPOSIT', 'DONE', 'Internal Transfer', null))
            ->toBe('INTERNAL_TRANSFER');
    });

    // ── Real internal transfers ──────────────────────────────────────────────

    it('classifies a real internal transfer (deposit side, no challenge offer) as INTERNAL_TRANSFER', function () {
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

    it('gateway matching is case-insensitive', function () {
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
