<?php

declare(strict_types=1);

namespace App\Services\Transaction;

/**
 * Classifies a transaction into a business category.
 *
 * Category values:
 *   EXTERNAL_DEPOSIT    — real client deposit via payment gateway
 *   EXTERNAL_WITHDRAWAL — real client withdrawal via payment gateway
 *   CHALLENGE_PURCHASE  — prop challenge bought via wallet (post-31 Mar 2026 format)
 *   CHALLENGE_REFUND    — prop challenge refund via TurboTrade Challenge gateway
 *   INTERNAL_TRANSFER   — wallet movement between accounts (including pre-31 Mar 2026
 *                         challenge purchases, which are indistinguishable from real
 *                         transfers — accepted ambiguity)
 *   UNCLASSIFIED        — non-DONE status or unrecognised pattern
 *
 * Classification rules (per BRAIN.md §Transaction Classification):
 *
 *   If status != DONE → UNCLASSIFIED
 *
 *   DEPOSIT + DONE:
 *     offer name contains challenge keyword → CHALLENGE_PURCHASE
 *     gateway = Internal Transfer           → INTERNAL_TRANSFER
 *     otherwise                             → EXTERNAL_DEPOSIT
 *
 *   WITHDRAWAL + DONE:
 *     gateway = TurboTrade Challenge        → CHALLENGE_REFUND
 *     gateway = Internal Transfer           → INTERNAL_TRANSFER
 *     otherwise                             → EXTERNAL_WITHDRAWAL
 */
class CategoryClassifier
{
    /** Case-insensitive keywords matched against the related offer name. */
    private const CHALLENGE_KEYWORDS = [
        'Instant Funded',
        'Evaluation',
        'Verification',
        'Consistency',
    ];

    /**
     * @param  string       $type        DEPOSIT or WITHDRAWAL
     * @param  string       $status      DONE, PENDING, FAILED, REVERSED, …
     * @param  string|null  $gatewayName paymentGatewayDetails.name from MTR
     * @param  string|null  $offerName   Name of the offer linked to the trading account
     */
    public static function classify(
        string $type,
        string $status,
        ?string $gatewayName,
        ?string $offerName,
    ): string {
        if (strtoupper($status) !== 'DONE') {
            return 'UNCLASSIFIED';
        }

        $gateway = strtolower(trim($gatewayName ?? ''));

        if (strtoupper($type) === 'DEPOSIT') {
            if ($offerName !== null && self::hasChallengeKeyword($offerName)) {
                return 'CHALLENGE_PURCHASE';
            }

            if ($gateway === 'internal transfer') {
                return 'INTERNAL_TRANSFER';
            }

            return 'EXTERNAL_DEPOSIT';
        }

        // WITHDRAWAL
        if ($gateway === 'turbotrade challenge') {
            return 'CHALLENGE_REFUND';
        }

        if ($gateway === 'internal transfer') {
            return 'INTERNAL_TRANSFER';
        }

        return 'EXTERNAL_WITHDRAWAL';
    }

    private static function hasChallengeKeyword(string $offerName): bool
    {
        $lower = strtolower($offerName);

        foreach (self::CHALLENGE_KEYWORDS as $keyword) {
            if (str_contains($lower, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }
}
