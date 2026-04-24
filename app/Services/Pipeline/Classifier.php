<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

/**
 * Classifies a MatchTrader offer or trading account into one of the MFU pipelines.
 *
 * Rules (ported from sync_from_matchtrader.py):
 *   MFU_CAPITAL  — offer name contains prop/challenge/evaluation keywords,
 *                  OR the offer UUID is in the known prop challenge UUID set
 *   MFU_ACADEMY  — offer name contains education/course keywords
 *   MFU_MARKETS  — everything else (live/real trading accounts)
 *   UNCLASSIFIED — no offer data available
 */
class Classifier
{
    private const CAPITAL_KEYWORDS = [
        'challenge', 'evaluation', 'phase', 'funded', 'prop',
        'instant', 'verification', 'consistency',
    ];

    private const ACADEMY_KEYWORDS = [
        'course', 'academy', 'education', 'training',
    ];

    /** Prop challenge offer UUIDs — populated from /v1/prop/challenges */
    private static array $propOfferUuids = [];

    /**
     * Set the known prop challenge offer UUIDs.
     * Call this after syncing /v1/prop/challenges before classifying offers.
     *
     * @param  string[]  $uuids
     */
    public static function setPropOfferUuids(array $uuids): void
    {
        self::$propOfferUuids = array_map('strtolower', $uuids);
    }

    /**
     * Classify by offer name and/or offer UUID.
     *
     * @param  string|null  $offerName   The offer's display name from MTR
     * @param  string|null  $offerUuid   The offer's UUID from MTR
     */
    public static function classify(?string $offerName, ?string $offerUuid = null): string
    {
        if ($offerName === null && $offerUuid === null) {
            return 'UNCLASSIFIED';
        }

        // UUID match takes precedence
        if ($offerUuid !== null && in_array(strtolower($offerUuid), self::$propOfferUuids, true)) {
            return 'MFU_CAPITAL';
        }

        if ($offerName === null) {
            // UUID is present but not in the prop set and we have no name — default to live trading
            return 'MFU_MARKETS';
        }

        $lower = strtolower($offerName);

        foreach (self::CAPITAL_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return 'MFU_CAPITAL';
            }
        }

        foreach (self::ACADEMY_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return 'MFU_ACADEMY';
            }
        }

        return 'MFU_MARKETS';
    }

    /**
     * Classify directly from a raw MTR offer array.
     */
    public static function classifyOffer(array $offer): string
    {
        return self::classify(
            offerName: $offer['name'] ?? null,
            offerUuid: $offer['uuid'] ?? null,
        );
    }
}
