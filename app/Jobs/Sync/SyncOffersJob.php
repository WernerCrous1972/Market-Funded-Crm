<?php

declare(strict_types=1);

namespace App\Jobs\Sync;

use App\Models\Branch;
use App\Models\Offer;
use App\Services\MatchTrader\Client;
use App\Services\Pipeline\Classifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncOffersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 30;
    public int $timeout = 180;

    public function __construct(public readonly bool $dryRun = false) {}

    public function handle(Client $mtr): void
    {
        Log::info('SyncOffersJob: starting');

        // Fetch all prop challenges once (plain array — API returns flat, not paginated).
        // Reused for both UUID seeding and phase-offer upsert so we never traverse
        // the generator twice (which would cause "already closed generator" errors).
        $allChallenges = $mtr->propChallenges(page: 0, size: 1000);

        // Seed the pipeline classifier with known prop challenge UUIDs
        $propOfferUuids = $this->collectPropOfferUuids($allChallenges);
        Classifier::setPropOfferUuids($propOfferUuids);

        $rawOffers = $mtr->offers();
        $created   = 0;
        $updated   = 0;

        foreach ($rawOffers as $raw) {
            $uuid = $raw['uuid'] ?? $raw['id'] ?? null;
            $name = trim($raw['name'] ?? '');

            if (! $uuid || ! $name) {
                continue;
            }

            $pipeline        = Classifier::classifyOffer($raw);
            $isPropChallenge = in_array(strtolower($uuid), array_map('strtolower', $propOfferUuids), true);

            if (! $this->dryRun) {
                $offer = Offer::updateOrCreate(
                    ['mtr_offer_uuid' => $uuid],
                    [
                        'name'             => $name,
                        'pipeline'         => $pipeline,
                        'is_demo'          => (bool) ($raw['demo'] ?? $raw['isDemo'] ?? false),
                        'is_prop_challenge' => $isPropChallenge,
                        'branch_uuid'      => $raw['branchUuid'] ?? null,
                        'raw_data'         => $raw,
                    ]
                );
                $offer->wasRecentlyCreated ? $created++ : $updated++;
            } else {
                Log::info("DRY-RUN offer: {$name} → {$pipeline}");
                $created++;
            }
        }

        // ── Prop challenge phase offers ───────────────────────────────────────
        // Upsert one offer row per challenge phase so that deposit-side
        // transactions linked to these phase accounts resolve to an offer
        // name containing the challenge keyword + brand code, enabling
        // CategoryClassifier to classify them as CHALLENGE_PURCHASE.
        $this->syncPropChallengeOffers($allChallenges, $created, $updated);

        Log::info("SyncOffersJob: done — created={$created} updated={$updated} prop_uuids=" . count($propOfferUuids));
    }

    private function syncPropChallengeOffers(array $allChallenges, int &$created, int &$updated): void
    {
        $includedBranchUuids = Branch::where('is_included', true)
            ->pluck('mtr_branch_uuid')
            ->flip()
            ->toArray();

        foreach ($allChallenges as $challenge) {
            if (!is_array($challenge)) {
                continue;
            }

            // Skip challenges on branches we don't manage
            $branchUuid = $challenge['branch']['uuid'] ?? null;
            if (!$branchUuid || !array_key_exists($branchUuid, $includedBranchUuids)) {
                continue;
            }

            $challengeName = trim($challenge['name'] ?? '');
            if (!$challengeName) {
                continue;
            }

            // Skip education/course challenges — they share phase names (Evaluation,
            // Verification, etc.) with trading challenges but are not prop purchases.
            if (Classifier::classify($challengeName) === 'MFU_ACADEMY') {
                continue;
            }

            foreach ($challenge['phases'] ?? [] as $phase) {
                $phaseOfferUuid = $phase['offerUuid'] ?? null;
                $phaseName      = trim($phase['phaseName'] ?? '');

                if (!$phaseOfferUuid || !$phaseName) {
                    continue;
                }

                $offerName = "{$challengeName} - {$phaseName}";
                $pipeline  = Classifier::classify($offerName, $phaseOfferUuid);

                if (!$this->dryRun) {
                    $offer = Offer::updateOrCreate(
                        ['mtr_offer_uuid' => $phaseOfferUuid],
                        [
                            'name'              => $offerName,
                            'pipeline'          => $pipeline,
                            'is_demo'           => false,
                            'is_prop_challenge' => true,
                            'branch_uuid'       => $branchUuid,
                            'raw_data'          => $phase,
                        ]
                    );
                    $offer->wasRecentlyCreated ? $created++ : $updated++;
                } else {
                    Log::info("DRY-RUN challenge offer: {$offerName} → {$pipeline}");
                    $created++;
                }
            }
        }
    }

    /**
     * @param  array<array>  $allChallenges  Raw challenge array from propChallenges()
     * @return string[]
     */
    private function collectPropOfferUuids(array $allChallenges): array
    {
        $uuids = [];

        foreach ($allChallenges as $challenge) {
            if (! is_array($challenge)) {
                continue;
            }
            foreach ($challenge['phases'] ?? [] as $phase) {
                $offerUuid = $phase['offerUuid'] ?? $phase['offer']['uuid'] ?? null;
                if ($offerUuid) {
                    $uuids[] = $offerUuid;
                }
            }
            $directUuid = $challenge['offerUuid'] ?? null;
            if ($directUuid) {
                $uuids[] = $directUuid;
            }
        }

        return array_unique($uuids);
    }
}
