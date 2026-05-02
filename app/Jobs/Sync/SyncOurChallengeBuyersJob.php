<?php

declare(strict_types=1);

namespace App\Jobs\Sync;

use App\Models\Activity;
use App\Models\Offer;
use App\Models\Person;
use App\Models\TradingAccount;
use App\Models\User;
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

/**
 * Imports people and trading-account records for challenge buyers whose CRM
 * account is on an excluded branch (or missing entirely from /v1/accounts).
 *
 * Ownership signal: a whole-word brand code (TTR, QT, MFU) in the challengeName
 * field from /v1/prop/accounts is the durable indicator that the person bought
 * a Market Funded product — regardless of the MTR branch their CRM record is on.
 *
 * See BRAIN.md §12 — Brand vs Branch: Customer Identity Rule.
 */
class SyncOurChallengeBuyersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;
    public int $timeout = 3600;

    public function __construct(
        public readonly bool $dryRun = false,
    ) {}

    public function handle(Client $mtr): void
    {
        Log::info('SyncOurChallengeBuyersJob: starting', ['dry_run' => $this->dryRun]);

        $excludedSources = array_map('strtolower', (array) config('matchtrader.excluded_lead_sources'));

        // ── Step 1: Build challengeId:phaseStep → Offer lookup ──────────────
        // Cross-references /v1/prop/challenges (live) with our offers table.
        $offerByUuid     = Offer::where('is_prop_challenge', true)
            ->get()
            ->keyBy('mtr_offer_uuid');

        $challengeOfferMap = []; // "challengeId:phaseStep" => Offer
        foreach ($mtr->allPropChallenges() as $challenge) {
            $challengeId = $challenge['challengeId'] ?? null;
            if (! $challengeId) {
                continue;
            }
            foreach ($challenge['phases'] ?? [] as $phase) {
                $offerUuid = $phase['offerUuid'] ?? null;
                $phaseStep = $phase['phaseStep'] ?? null;
                if ($offerUuid && $phaseStep !== null && isset($offerByUuid[$offerUuid])) {
                    $challengeOfferMap["{$challengeId}:{$phaseStep}"] = $offerByUuid[$offerUuid];
                }
            }
        }

        // ── Step 2: Process /v1/prop/accounts ───────────────────────────────
        // CRM enrichment is resolved lazily per-record:
        //   • If the person already exists in our DB → no MTR lookup needed (data already imported).
        //   • If the person is missing (ghost/cross-branch record) → call /v1/accounts/by-email/{email}.
        // This avoids loading all 29k+ accounts into a PHP array (memory bomb).
        $stats = [
            'total'      => 0,
            'skipped'    => 0,
            'people_new' => 0,
            'ta_new'     => 0,
            'ta_exists'  => 0,
            'errors'     => 0,
        ];

        foreach ($mtr->allPropAccounts() as $propAccount) {
            $stats['total']++;

            try {
                $challengeName = $propAccount['challengeName'] ?? '';

                // Brand filter — whole-word TTR, QT, or MFU (case-sensitive)
                if (! $this->hasOurBrandCode($challengeName)) {
                    $stats['skipped']++;
                    continue;
                }

                $accountId = $propAccount['accountId'] ?? null;
                if (! $accountId) {
                    $stats['skipped']++;
                    continue;
                }

                // Skip if we already have this trading account
                if (TradingAccount::where('mtr_account_uuid', $accountId)->exists()) {
                    $stats['ta_exists']++;
                    continue;
                }

                $rawEmail = $propAccount['email'] ?? '';
                if (! EmailNormalizer::isValid($rawEmail)) {
                    $stats['skipped']++;
                    continue;
                }
                $email = EmailNormalizer::normalize($rawEmail);

                // Lazy CRM enrichment: use existing DB record if present;
                // otherwise fetch from MTR only for true ghost/cross-branch records.
                $existingPerson = Person::where('email', $email)->first();
                $crmRaw         = null;
                if ($existingPerson === null) {
                    $crmRaw = $mtr->accountByEmail($email);
                }

                // Lead source filter — applied only when a fresh CRM record is fetched
                // (existing people already passed this filter during the accounts sync).
                $leadSource = $crmRaw ? ($crmRaw['leadDetails']['source'] ?? '') : '';
                if ($leadSource && in_array(strtolower($leadSource), $excludedSources, true)) {
                    $stats['skipped']++;
                    continue;
                }

                // Look up offer for this challenge phase
                $challengeId = $propAccount['challengeId'] ?? null;
                $phaseStep   = $propAccount['phaseStep'] ?? null;
                $offer       = ($challengeId && $phaseStep !== null)
                    ? ($challengeOfferMap["{$challengeId}:{$phaseStep}"] ?? null)
                    : null;

                if ($this->dryRun) {
                    Log::info('DRY-RUN SyncOurChallengeBuyersJob: would process', [
                        'email'         => $email,
                        'challengeName' => $challengeName,
                        'accountId'     => $accountId,
                        'offer'         => $offer?->name,
                        'has_crm'       => $crmRaw !== null || $existingPerson !== null,
                    ]);
                    $stats['ta_new']++;
                    continue;
                }

                DB::transaction(function () use (
                    $email, $propAccount, $existingPerson, $crmRaw, $offer, $accountId, &$stats
                ): void {
                    // ── Find or create person ──────────────────────────────
                    $person = $existingPerson;
                    $isNew  = $person === null;

                    if ($isNew) {
                        $personData = $this->buildPersonData($email, $propAccount, $crmRaw);
                        $personData['imported_via_challenge'] = true;
                        $person = Person::create($personData);
                        $stats['people_new']++;

                        $this->detectAndLinkDuplicate($person, $stats);

                        Activity::record(
                            $person->id,
                            'IMPORTED',
                            "Imported via challenge: {$propAccount['challengeName']}",
                            ['challenge_name' => $propAccount['challengeName'], 'account_id' => $accountId],
                        );
                    }

                    // ── Create trading account ─────────────────────────────
                    $isActive = in_array(
                        $propAccount['status'] ?? '',
                        ['ACTIVE_PARTICIPATING_IN_CHALLENGE', 'ACTIVE_FUNDED'],
                        true
                    );

                    TradingAccount::create([
                        'person_id'        => $person->id,
                        'mtr_account_uuid' => $accountId,
                        'mtr_login'        => (string) ($propAccount['login'] ?? ''),
                        'offer_id'         => $offer?->id,
                        'pipeline'         => 'MFU_CAPITAL',
                        'is_demo'          => false,
                        'is_active'        => $isActive,
                        'opened_at'        => $this->parseDateTime($propAccount['created'] ?? null),
                    ]);

                    $stats['ta_new']++;
                });
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('SyncOurChallengeBuyersJob: error', [
                    'accountId' => $propAccount['accountId'] ?? 'unknown',
                    'email'     => $propAccount['email'] ?? 'unknown',
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        Log::info('SyncOurChallengeBuyersJob: complete', $stats);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Returns true if the challenge name contains TTR, QT, or MFU as a whole word.
     * Uses the same brand codes as CategoryClassifier (whole-word, case-sensitive).
     */
    private function hasOurBrandCode(string $challengeName): bool
    {
        foreach (config('matchtrader.our_brand_codes', ['TTR', 'QT', 'MFU']) as $code) {
            if (preg_match('/\b' . preg_quote($code, '/') . '\b/', $challengeName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds the person data array for a new person.
     * If a CRM raw record is available, uses its full profile.
     * Falls back to the minimal data from the prop/accounts record.
     */
    private function buildPersonData(string $email, array $propAccount, ?array $crmRaw): array
    {
        if ($crmRaw !== null) {
            $phone    = PhoneNormalizer::normalize($crmRaw['contactDetails']['phoneNumber'] ?? '');
            $isClient = ! empty($crmRaw['leadDetails']['becomeActiveClientTime']);

            $accountManagerName = (function ($am) {
                if (is_array($am)) return $am['name'] ?? null;
                return $am ?: null;
            })($crmRaw['accountConfiguration']['accountManager'] ?? null);

            $accountManagerUserId = $accountManagerName
                ? User::where('name', $accountManagerName)->value('id')
                : null;

            return [
                'first_name'      => trim($crmRaw['personalDetails']['firstname'] ?? ''),
                'last_name'       => trim($crmRaw['personalDetails']['lastname'] ?? ''),
                'email'           => $email,
                'phone_e164'      => $phone,
                'phone_country_code' => $phone ? PhoneNormalizer::countryCode($phone) : null,
                'country'         => $crmRaw['addressDetails']['country'] ?? null,
                'contact_type'    => $isClient ? 'CLIENT' : 'LEAD',
                'lead_status'     => $crmRaw['leadDetails']['status'] ?? null,
                'lead_source'     => $crmRaw['leadDetails']['source'] ?: null,
                'affiliate'       => $crmRaw['leadDetails']['referral'] ?? null,
                'branch'          => $this->resolveBranchName($crmRaw),
                'branch_id'       => $this->resolveBranchId($crmRaw),
                'account_manager' => $accountManagerName,
                'account_manager_user_id' => $accountManagerUserId,
                'became_active_client_at' => $isClient
                    ? $this->parseDateTime($crmRaw['leadDetails']['becomeActiveClientTime'])
                    : null,
                'last_online_at'     => $this->parseDateTime($crmRaw['lastOnlineTime'] ?? null),
                'mtr_last_synced_at' => now(),
                'mtr_created_at'     => $this->parseDateTime($crmRaw['created'] ?? null),
                'mtr_updated_at'     => $this->parseDateTime($crmRaw['updated'] ?? null),
            ];
        }

        // Ghost record — no CRM account found in /v1/accounts. Build from prop/accounts only.
        $nameParts = explode(' ', trim($propAccount['name'] ?? ''), 2);

        return [
            'first_name'         => $nameParts[0] ?? '',
            'last_name'          => $nameParts[1] ?? '',
            'email'              => $email,
            'phone_e164'         => null,
            'phone_country_code' => null,
            'country'            => null,
            'contact_type'       => 'LEAD',
            'lead_status'        => null,
            'lead_source'        => null,
            'affiliate'          => null,
            'branch'             => null,       // unknown — no CRM record
            'branch_id'          => null,       // null = invisible to scoped users until next sync
            'account_manager'    => null,
            'account_manager_user_id' => null,
            'became_active_client_at' => null,
            'last_online_at'     => null,
            'mtr_last_synced_at' => now(),
            'mtr_created_at'     => $this->parseDateTime($propAccount['created'] ?? null),
            'mtr_updated_at'     => null,
        ];
    }

    /**
     * Resolves the branch name from a raw CRM account record.
     * Returns the branch name string (may be an excluded branch — stored truthfully).
     */
    private function resolveBranchName(array $crmRaw): ?string
    {
        return $this->resolveBranchModel($crmRaw)?->name;
    }

    /**
     * Resolves the branch UUID (FK to branches.id) from a raw CRM account record.
     * Returns null if branch UUID not found in our DB — person will be invisible to
     * scoped users until the next sync resolves it (null branch_id fail-safe).
     */
    private function resolveBranchId(array $crmRaw): ?string
    {
        return $this->resolveBranchModel($crmRaw)?->id;
    }

    private function resolveBranchModel(array $crmRaw): ?\App\Models\Branch
    {
        $branchUuid = $crmRaw['accountConfiguration']['branchUuid'] ?? null;
        if (! $branchUuid) {
            return null;
        }

        static $branchCache = null;
        if ($branchCache === null) {
            $branchCache = \App\Models\Branch::all()->keyBy('mtr_branch_uuid');
        }

        return $branchCache[$branchUuid] ?? null;
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
            $stats['duplicates'] = ($stats['duplicates'] ?? 0) + 1;
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
