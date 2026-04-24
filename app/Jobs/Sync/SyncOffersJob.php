<?php

declare(strict_types=1);

namespace App\Jobs\Sync;

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

        // Load prop challenge offer UUIDs first so classification is accurate
        $propOfferUuids = $this->collectPropOfferUuids($mtr);
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

        Log::info("SyncOffersJob: done — created={$created} updated={$updated} prop_uuids=" . count($propOfferUuids));
    }

    /** @return string[] */
    private function collectPropOfferUuids(Client $mtr): array
    {
        $uuids = [];

        try {
            foreach ($mtr->allPropChallenges() as $challenge) {
                if (! is_array($challenge)) {
                    continue;
                }
                // Each challenge has phases, each phase has an offerUuid
                foreach ($challenge['phases'] ?? [] as $phase) {
                    $offerUuid = $phase['offerUuid'] ?? $phase['offer']['uuid'] ?? null;
                    if ($offerUuid) {
                        $uuids[] = $offerUuid;
                    }
                }
                // Also capture direct offer reference if present
                $directUuid = $challenge['offerUuid'] ?? null;
                if ($directUuid) {
                    $uuids[] = $directUuid;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SyncOffersJob: could not load prop challenges for UUID seeding: ' . $e->getMessage());
        }

        return array_unique($uuids);
    }
}
