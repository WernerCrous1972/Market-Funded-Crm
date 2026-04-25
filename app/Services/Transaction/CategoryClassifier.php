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
 *     offer name contains challenge keyword AND our brand code → CHALLENGE_PURCHASE
 *     gateway = Internal Transfer                              → INTERNAL_TRANSFER
 *     otherwise                                               → EXTERNAL_DEPOSIT
 *
 *   WITHDRAWAL + DONE:
 *     gateway = TurboTrade Challenge        → CHALLENGE_REFUND
 *     gateway = Internal Transfer           → INTERNAL_TRANSFER
 *     otherwise                             → EXTERNAL_WITHDRAWAL
 *
 * Challenge keywords and brand codes are read from config/matchtrader.php so
 * that new brands can be added without touching this class.
 */
class CategoryClassifier
{
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
            if ($offerName !== null && self::isOurChallenge($offerName)) {
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

    /**
     * Returns true when the offer name contains both a challenge keyword (case-insensitive)
     * and one of our brand codes as a whole word (case-sensitive, space-bounded).
     *
     * Affiliate brokers share the same MTR instance and use the same challenge keywords but
     * different brand codes (e.g. ATY, SOT, EAR). Those must NOT classify as CHALLENGE_PURCHASE.
     */
    private static function isOurChallenge(string $offerName): bool
    {
        if (!self::hasChallengeKeyword($offerName)) {
            return false;
        }

        // Pad with spaces so we can match brand codes at start/end of string too
        $padded = ' ' . $offerName . ' ';

        foreach (config('matchtrader.our_brand_codes', []) as $code) {
            if (str_contains($padded, ' ' . $code . ' ')) {
                return true;
            }
        }

        return false;
    }

    private static function hasChallengeKeyword(string $offerName): bool
    {
        $lower = strtolower($offerName);

        foreach (config('matchtrader.challenge_keywords', []) as $keyword) {
            if (str_contains($lower, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }
}
