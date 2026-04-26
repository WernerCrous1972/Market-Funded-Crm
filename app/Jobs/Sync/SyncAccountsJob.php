<?php

declare(strict_types=1);

namespace App\Jobs\Sync;

use App\Models\Activity;
use App\Models\Branch;
use App\Models\Person;
use App\Services\MatchTrader\Client;
use App\Services\Normalizer\EmailNormalizer;
use App\Services\Normalizer\PhoneNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncAccountsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;
    public int $timeout = 3600; // accounts sync can run long

    public function __construct(
        public readonly bool $dryRun = false,
        public readonly bool $incremental = false,
    ) {}

    public function handle(Client $mtr): void
    {
        Log::info('SyncAccountsJob: starting', [
            'dry_run'     => $this->dryRun,
            'incremental' => $this->incremental,
        ]);

        $includedBranches = array_map('strtolower', (array) config('matchtrader.included_branches'));
        $excludedSources  = array_map('strtolower', (array) config('matchtrader.excluded_lead_sources'));

        // Build branch lookup: mtr_branch_uuid → name (avoids N+1 on every account)
        $branchLookup = Branch::all()->keyBy('mtr_branch_uuid')->map(fn (Branch $b) => $b->name);

        $stats = [
            'total'      => 0,
            'skipped'    => 0,
            'people_new' => 0,
            'people_upd' => 0,
            'duplicates' => 0,
            'errors'     => 0,
        ];

        foreach ($mtr->allAccounts() as $raw) {
            $stats['total']++;

            try {
                // ── Branch filter ──────────────────────────────────────────
                // People on excluded branches are skipped entirely — no DB write.
                // This intentionally preserves records already imported by
                // SyncOurChallengeBuyersJob (brand-first imports): because we
                // never reach the upsert path for excluded branches, their records
                // are left untouched even if the person later appears here.
                $branchUuid = $raw['accountConfiguration']['branchUuid'] ?? null;
                $branchName = $branchUuid ? (string) ($branchLookup[$branchUuid] ?? '') : '';
                if (! in_array(strtolower($branchName), $includedBranches, true)) {
                    $stats['skipped']++;
                    continue;
                }

                // ── Email validation ───────────────────────────────────────
                $rawEmail = $raw['email'] ?? '';
                if (! EmailNormalizer::isValid($rawEmail)) {
                    $stats['skipped']++;
                    continue;
                }
                $email = EmailNormalizer::normalize($rawEmail);

                // ── Lead source filter ─────────────────────────────────────
                $leadSource = $raw['leadDetails']['source'] ?? '';
                if (in_array(strtolower($leadSource), $excludedSources, true)) {
                    $stats['skipped']++;
                    continue;
                }

                // ── Extract fields ─────────────────────────────────────────
                $phone    = PhoneNormalizer::normalize($raw['contactDetails']['phoneNumber'] ?? '');
                $country  = $raw['addressDetails']['country'] ?? null;
                $isClient = ! empty($raw['leadDetails']['becomeActiveClientTime']);

                $personData = [
                    'first_name'      => trim($raw['personalDetails']['firstname'] ?? ''),
                    'last_name'       => trim($raw['personalDetails']['lastname'] ?? ''),
                    'email'           => $email,
                    'phone_e164'      => $phone,
                    'phone_country_code' => $phone ? PhoneNormalizer::countryCode($phone) : null,
                    'country'         => $country,
                    'lead_status'     => $raw['leadDetails']['status'] ?? null,
                    'lead_source'     => $leadSource ?: null,
                    'affiliate'       => $raw['leadDetails']['referral'] ?? null,
                    'branch'          => $branchName,
                    'account_manager' => (function ($am) {
                        if (is_array($am)) return $am['name'] ?? null;
                        return $am ?: null;
                    })($raw['accountConfiguration']['accountManager'] ?? null),
                    'became_active_client_at' => $isClient
                        ? $this->parseDateTime($raw['leadDetails']['becomeActiveClientTime'])
                        : null,
                    'last_online_at'     => $this->parseDateTime($raw['lastOnlineTime'] ?? null),
                    'mtr_last_synced_at' => now(),
                    'mtr_created_at'     => $this->parseDateTime($raw['created'] ?? null),
                    'mtr_updated_at'     => $this->parseDateTime($raw['updated'] ?? null),
                ];

                if ($this->dryRun) {
                    Log::info("DRY-RUN account: {$email} branch={$branchName} client=" . ($isClient ? 'yes' : 'no'));
                    $stats['people_new']++;
                    continue;
                }

                DB::transaction(function () use (
                    $email, $personData, $isClient, &$stats
                ) {
                    // ── Upsert Person ──────────────────────────────────────
                    // Note: /v1/accounts returns CRM contact profiles only.
                    // TradingAccount records are created by SyncDepositsJob and
                    // SyncWithdrawalsJob from accountInfo.tradingAccount data.
                    $person  = Person::where('email', $email)->first();
                    $isNew   = $person === null;

                    if ($isNew) {
                        $personData['contact_type'] = $isClient ? 'CLIENT' : 'LEAD';
                        $person = Person::create($personData);
                        $stats['people_new']++;

                        // Check for phone/name duplicates
                        $this->detectAndLinkDuplicate($person, $stats);
                    } else {
                        // Upgrade-only contact_type rule
                        if ($isClient && $person->contact_type === 'LEAD') {
                            $personData['contact_type'] = 'CLIENT';
                            Activity::record(
                                $person->id,
                                'STATUS_CHANGED',
                                "Upgraded from LEAD to CLIENT",
                                ['became_active_client_at' => $personData['became_active_client_at']],
                            );
                        } else {
                            unset($personData['contact_type']);
                        }

                        $person->update($personData);
                        $stats['people_upd']++;
                    }
                });
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('SyncAccountsJob: error processing account', [
                    'email' => $raw['email'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('SyncAccountsJob: complete', $stats);
    }

    private function detectAndLinkDuplicate(Person $newPerson, array &$stats): void
    {
        if (! $newPerson->phone_e164) {
            return;
        }

        $duplicate = Person::where('phone_e164', $newPerson->phone_e164)
            ->where('id', '!=', $newPerson->id)
            ->whereNull('duplicate_of_person_id')
            ->first();

        if ($duplicate) {
            $newPerson->update(['duplicate_of_person_id' => $duplicate->id]);
            Activity::record(
                $newPerson->id,
                'DUPLICATE_DETECTED',
                "Possible duplicate of {$duplicate->email} (same phone)",
                ['original_person_id' => $duplicate->id],
            );
            $stats['duplicates']++;
        }
    }

    private function parseDateTime(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
