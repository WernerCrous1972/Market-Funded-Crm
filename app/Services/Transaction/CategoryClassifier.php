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
 *     offer name contains challenge keyword AND our brand code (case-sensitive) → CHALLENGE_PURCHASE
 *     gateway = Internal Transfer                                               → INTERNAL_TRANSFER
 *     otherwise                                                                → EXTERNAL_DEPOSIT
 *
 *   WITHDRAWAL + DONE:
 *     gateway = TurboTrade Challenge:
 *       occurred_at >= PURCHASE_CUTOFF_DATE (2026-04-01) → CHALLENGE_REFUND
 *         (post-changeover: purchases moved to deposit side; any TurboTrade
 *          Challenge withdrawal is now a refund regardless of brand)
 *       offer contains our brand code (case-insensitive) → CHALLENGE_PURCHASE
 *         (pre-April-2026 purchase: MTR booked these as wallet withdrawals)
 *       otherwise                                        → CHALLENGE_REFUND
 *         (affiliate brand challenge refund, or no offer name)
 *     gateway = Internal Transfer                        → INTERNAL_TRANSFER
 *     otherwise                                          → EXTERNAL_WITHDRAWAL
 *
 * Challenge keywords and brand codes are read from config/matchtrader.php so
 * that new brands can be added without touching this class.
 */
class CategoryClassifier
{
    /**
     * On 2026-04-01, MTR switched challenge purchases to the deposit side.
     * Any TurboTrade Challenge withdrawal on or after this date is a refund,
     * not a purchase — regardless of the offer name or brand code.
     */
    private const PURCHASE_CUTOFF_DATE = '2026-04-01';

    /**
     * @param  string       $type        DEPOSIT or WITHDRAWAL
     * @param  string       $status      DONE, PENDING, FAILED, REVERSED, …
     * @param  string|null  $gatewayName paymentGatewayDetails.name from MTR
     * @param  string|null  $offerName   Name of the offer linked to the trading account
     * @param  string|null  $occurredAt  ISO-8601 transaction date; used to enforce the
     *                                   April 2026 cutoff on the withdrawal side
     */
    public static function classify(
        string $type,
        string $status,
        ?string $gatewayName,
        ?string $offerName,
        ?string $occurredAt = null,
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
            // Post-cutoff: challenge purchases moved to the deposit side on 2026-04-01.
            // Any TurboTrade Challenge withdrawal on or after that date is a refund.
            if ($occurredAt !== null && substr($occurredAt, 0, 10) >= self::PURCHASE_CUTOFF_DATE) {
                return 'CHALLENGE_REFUND';
            }

            // Pre-cutoff: MTR booked challenge purchases as wallet withdrawals.
            // Identify ours by brand code; affiliate brands remain CHALLENGE_REFUND.
            return self::hasOurBrandCode($offerName)
                ? 'CHALLENGE_PURCHASE'
                : 'CHALLENGE_REFUND';
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

    /**
     * Returns true when the offer name contains one of our brand codes as a whole word.
     * Used on the withdrawal side; matching is case-insensitive because historical
     * offer names were not always consistently cased.
     */
    private static function hasOurBrandCode(?string $offerName): bool
    {
        if ($offerName === null || $offerName === '') {
            return false;
        }

        $padded = ' ' . $offerName . ' ';

        foreach (config('matchtrader.our_brand_codes', []) as $code) {
            if (str_contains(strtolower($padded), ' ' . strtolower($code) . ' ')) {
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
