<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Offer;
use App\Models\Person;
use App\Models\TradingAccount;
use App\Models\Transaction;
use App\Services\MatchTrader\Client;
use App\Services\Normalizer\EmailNormalizer;
use App\Services\Pipeline\Classifier;
use App\Services\Transaction\CategoryClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time backfill that pulls the full transaction history from the MTR API
 * and inserts any rows not already present in the database.
 *
 * Covers both deposits and withdrawals from the given date onwards.
 * Existing transactions are left untouched (idempotent), with ONE exception:
 * if an existing transaction is CHALLENGE_REFUND and the API now provides an
 * offer name that identifies it as our brand, the category is promoted to
 * CHALLENGE_PURCHASE. This corrects rows that were stored before the offer
 * name was available (all such rows have trading_account_id = NULL).
 *
 * Usage:
 *   php artisan backfill:full-history                      # live run from 2025-03-01
 *   php artisan backfill:full-history --dry-run            # preview counts, no DB writes
 *   php artisan backfill:full-history --since=2025-01-01   # custom start date (ISO-8601)
 */
class BackfillFullHistory extends Command
{
    protected $signature = 'backfill:full-history
                            {--since=2025-03-01 : Start date for the fetch (ISO-8601, e.g. 2025-03-01)}
                            {--dry-run          : Fetch and count without inserting}';

    protected $description = 'Backfill full transaction history from MTR API (March 2025 onwards)';

    /** @var array<string, int> */
    private array $insertedByCategory = [
        'EXTERNAL_DEPOSIT'    => 0,
        'EXTERNAL_WITHDRAWAL' => 0,
        'CHALLENGE_PURCHASE'  => 0,
        'CHALLENGE_REFUND'    => 0,
        'INTERNAL_TRANSFER'   => 0,
        'UNCLASSIFIED'        => 0,
    ];

    public function handle(Client $mtr): int
    {
        $since    = (string) $this->option('since');
        $isDryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '%s Full history backfill from %s',
            $isDryRun ? '[DRY RUN]' : '[LIVE]',
            $since,
        ));

        // ── Pre-load lookup tables ────────────────────────────────────────────
        $includedBranchUuids = Branch::where('is_included', true)
            ->pluck('mtr_branch_uuid')
            ->flip()
            ->toArray();

        $offerLookup = Offer::all()->keyBy('mtr_offer_uuid');
        $propUuids   = Offer::where('is_prop_challenge', true)->pluck('mtr_offer_uuid')->toArray();
        Classifier::setPropOfferUuids($propUuids);

        $excludedGateways = array_map('strtolower', (array) config('matchtrader.excluded_gateways'));
        $excludedRemarks  = array_map('strtolower', (array) config('matchtrader.excluded_remarks'));
        $excludedSources  = array_map('strtolower', (array) config('matchtrader.excluded_lead_sources'));

        $stats = [
            'deposits_fetched'    => 0,
            'withdrawals_fetched' => 0,
            'inserted'            => 0,
            'skipped_existing'    => 0,
            'skipped_filtered'    => 0,
            'reclassified'        => 0,
            'errors'              => 0,
            'earliest_date'       => null,
            'latest_date'         => null,
        ];

        // ── Deposits ──────────────────────────────────────────────────────────
        $this->info("Fetching deposits since {$since}…");

        foreach ($mtr->allDeposits($since) as $raw) {
            $stats['deposits_fetched']++;

            try {
                $this->processDeposit(
                    $raw, $isDryRun,
                    $includedBranchUuids, $offerLookup,
                    $excludedGateways, $excludedRemarks,
                    $stats,
                );
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('BackfillFullHistory deposit error', [
                    'uuid'  => $raw['uuid'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ── Withdrawals ───────────────────────────────────────────────────────
        $this->info("Fetching withdrawals since {$since}…");

        foreach ($mtr->allWithdrawals($since) as $raw) {
            $stats['withdrawals_fetched']++;

            try {
                $this->processWithdrawal(
                    $raw, $isDryRun,
                    $includedBranchUuids, $offerLookup,
                    $excludedGateways, $excludedRemarks, $excludedSources,
                    $stats,
                );
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('BackfillFullHistory withdrawal error', [
                    'uuid'  => $raw['uuid'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ── Report ────────────────────────────────────────────────────────────
        $this->newLine();
        $totalFetched = $stats['deposits_fetched'] + $stats['withdrawals_fetched'];

        $this->table(
            ['Metric', 'Value'],
            [
                ['Deposits fetched from API',     number_format($stats['deposits_fetched'])],
                ['Withdrawals fetched from API',   number_format($stats['withdrawals_fetched'])],
                ['Total fetched',                  number_format($totalFetched)],
                ['New rows inserted',              number_format($stats['inserted'])],
                ['Existing rows reclassified',     number_format($stats['reclassified'])],
                ['Skipped (already in DB)',        number_format($stats['skipped_existing'])],
                ['Skipped (filtered out)',         number_format($stats['skipped_filtered'])],
                ['Errors',                         number_format($stats['errors'])],
                ['Date range (API)',                ($stats['earliest_date'] ?? '—') . ' → ' . ($stats['latest_date'] ?? '—')],
            ]
        );

        if ($stats['inserted'] > 0 || $isDryRun) {
            $this->newLine();
            $this->table(
                ['Category', 'Inserted'],
                collect($this->insertedByCategory)
                    ->map(fn ($cnt, $cat) => [$cat, number_format($cnt)])
                    ->values()
                    ->toArray()
            );
        }

        if ($isDryRun) {
            $this->warn('Dry run complete — no changes written. Re-run without --dry-run to apply.');
        } else {
            $this->info('Backfill complete.');
        }

        return self::SUCCESS;
    }

    // ── Deposit processing ────────────────────────────────────────────────────

    private function processDeposit(
        array $raw,
        bool $isDryRun,
        array $includedBranchUuids,
        $offerLookup,
        array $excludedGateways,
        array $excludedRemarks,
        array &$stats,
    ): void {
        $accountInfo = $raw['accountInfo'] ?? [];
        $paymentInfo = $raw['paymentRequestInfo'] ?? [];
        $financials  = $paymentInfo['financialDetails'] ?? [];
        $gatewayInfo = $paymentInfo['paymentGatewayDetails'] ?? [];

        // Branch filter
        $branchUuid = $accountInfo['branchUuid'] ?? null;
        if ($branchUuid && !array_key_exists($branchUuid, $includedBranchUuids)) {
            $stats['skipped_filtered']++;
            return;
        }

        // Status filter (DONE only)
        $status = strtoupper($financials['status'] ?? '');
        if ($status !== 'DONE') {
            $stats['skipped_filtered']++;
            return;
        }

        // Gateway filter
        $gateway = strtolower(trim($gatewayInfo['name'] ?? ''));
        if (in_array($gateway, $excludedGateways, true)) {
            $stats['skipped_filtered']++;
            return;
        }

        // Remark filter
        $remark = strtolower(trim($raw['remark'] ?? ''));
        foreach ($excludedRemarks as $excluded) {
            if (str_contains($remark, $excluded)) {
                $stats['skipped_filtered']++;
                return;
            }
        }

        $mtrUuid = $raw['uuid'] ?? null;
        if (!$mtrUuid) {
            $stats['skipped_filtered']++;
            return;
        }

        // Track date range
        $this->trackDate($raw['created'] ?? null, $stats);

        // Skip existing rows
        if (Transaction::where('mtr_transaction_uuid', $mtrUuid)->exists()) {
            $stats['skipped_existing']++;
            return;
        }

        $rawEmail = $accountInfo['email'] ?? '';
        if (!EmailNormalizer::isValid($rawEmail)) {
            $stats['skipped_filtered']++;
            return;
        }

        $email  = EmailNormalizer::normalize($rawEmail);
        $person = Person::where('email', $email)->first();

        if (!$person) {
            $stats['skipped_filtered']++;
            return;
        }

        $taInfo    = $accountInfo['tradingAccount'] ?? [];
        $taUuid    = $taInfo['uuid'] ?? null;
        $offerUuid = $taInfo['offerUuid'] ?? null;
        $offer     = $offerUuid ? $offerLookup->get($offerUuid) : null;
        $pipeline  = $offer?->pipeline ?? Classifier::classify($offer?->name ?? $offerUuid, $offerUuid);

        $amountCents = (int) round((float) ($financials['amount'] ?? 0) * 100);

        $category = CategoryClassifier::classify(
            type:        'DEPOSIT',
            status:      'DONE',
            gatewayName: $gatewayInfo['name'] ?? null,
            offerName:   $offer?->name,
        );

        $this->insertedByCategory[$category]++;
        $stats['inserted']++;

        if ($isDryRun) {
            return;
        }

        $tradingAcc = null;
        if ($taUuid) {
            $tradingAcc = TradingAccount::updateOrCreate(
                ['mtr_account_uuid' => $taUuid],
                [
                    'person_id' => $person->id,
                    'mtr_login' => (string) ($taInfo['login'] ?? '') ?: null,
                    'offer_id'  => $offer?->id,
                    'pipeline'  => $pipeline,
                    'is_demo'   => false,
                    'is_active' => true,
                    'opened_at' => null,
                ]
            );
        }

        Transaction::create([
            'person_id'            => $person->id,
            'trading_account_id'   => $tradingAcc?->id,
            'mtr_transaction_uuid' => $mtrUuid,
            'type'                 => 'DEPOSIT',
            'amount_cents'         => $amountCents,
            'currency'             => strtoupper($financials['currency'] ?? 'USD'),
            'status'               => 'DONE',
            'gateway_name'         => $gatewayInfo['name'] ?? null,
            'remark'               => $raw['remark'] ?? null,
            'occurred_at'          => \Carbon\Carbon::parse($raw['created'] ?? now())->toIso8601String(),
            'synced_at'            => now()->toIso8601String(),
            'pipeline'             => $pipeline,
            'category'             => $category,
        ]);
    }

    // ── Withdrawal processing ─────────────────────────────────────────────────

    private function processWithdrawal(
        array $raw,
        bool $isDryRun,
        array $includedBranchUuids,
        $offerLookup,
        array $excludedGateways,
        array $excludedRemarks,
        array $excludedSources,
        array &$stats,
    ): void {
        $accountInfo = $raw['accountInfo'] ?? [];
        $paymentInfo = $raw['paymentRequestInfo'] ?? [];
        $financials  = $paymentInfo['financialDetails'] ?? [];
        $gatewayInfo = $paymentInfo['paymentGatewayDetails'] ?? [];

        // Branch filter
        $branchUuid = $accountInfo['branchUuid'] ?? null;
        if ($branchUuid && !array_key_exists($branchUuid, $includedBranchUuids)) {
            $stats['skipped_filtered']++;
            return;
        }

        // Status filter
        $status = strtoupper($financials['status'] ?? '');
        if ($status !== 'DONE') {
            $stats['skipped_filtered']++;
            return;
        }

        // Gateway filter
        $gateway = strtolower(trim($gatewayInfo['name'] ?? ''));
        if (in_array($gateway, $excludedGateways, true)) {
            $stats['skipped_filtered']++;
            return;
        }

        // Lead source filter
        $leadSource = strtolower($accountInfo['leadDetails']['source'] ?? '');
        if (in_array($leadSource, $excludedSources, true)) {
            $stats['skipped_filtered']++;
            return;
        }

        $mtrUuid = $raw['uuid'] ?? null;
        if (!$mtrUuid) {
            $stats['skipped_filtered']++;
            return;
        }

        // Track date range
        $this->trackDate($raw['created'] ?? null, $stats);

        $taInfo    = $accountInfo['tradingAccount'] ?? [];
        $offerUuid = $taInfo['offerUuid'] ?? null;
        $offer     = $offerUuid ? $offerLookup->get($offerUuid) : null;

        // Check for existing row — may need reclassification for CHALLENGE_REFUND rows
        // that were stored without offer data (trading_account_id = NULL)
        $existing = Transaction::where('mtr_transaction_uuid', $mtrUuid)->first();

        if ($existing) {
            // Promote CHALLENGE_REFUND → CHALLENGE_PURCHASE when we now have the offer
            // name and it identifies this as our brand's challenge purchase.
            // This corrects rows stored before offer linkage was possible.
            if (
                $existing->category === 'CHALLENGE_REFUND'
                && $offer !== null
            ) {
                $newCategory = CategoryClassifier::classify(
                    type:        'WITHDRAWAL',
                    status:      'DONE',
                    gatewayName: $gatewayInfo['name'] ?? null,
                    offerName:   $offer->name,
                );

                if ($newCategory === 'CHALLENGE_PURCHASE') {
                    $stats['reclassified']++;
                    if (!$isDryRun) {
                        DB::table('transactions')
                            ->where('id', $existing->id)
                            ->update(['category' => 'CHALLENGE_PURCHASE']);
                    }
                    return;
                }
            }

            $stats['skipped_existing']++;
            return;
        }

        $rawEmail = $accountInfo['email'] ?? '';
        if (!EmailNormalizer::isValid($rawEmail)) {
            $stats['skipped_filtered']++;
            return;
        }

        $email  = EmailNormalizer::normalize($rawEmail);
        $person = Person::where('email', $email)->first();

        if (!$person) {
            $stats['skipped_filtered']++;
            return;
        }

        $taUuid   = $taInfo['uuid'] ?? null;
        $pipeline = $offer?->pipeline ?? Classifier::classify($offer?->name ?? $offerUuid, $offerUuid);

        $amountCents = (int) round((float) ($financials['amount'] ?? 0) * 100);

        $category = CategoryClassifier::classify(
            type:        'WITHDRAWAL',
            status:      'DONE',
            gatewayName: $gatewayInfo['name'] ?? null,
            offerName:   $offer?->name,
        );

        $this->insertedByCategory[$category]++;
        $stats['inserted']++;

        if ($isDryRun) {
            return;
        }

        $tradingAcc = null;
        if ($taUuid) {
            $tradingAcc = TradingAccount::updateOrCreate(
                ['mtr_account_uuid' => $taUuid],
                [
                    'person_id' => $person->id,
                    'mtr_login' => (string) ($taInfo['login'] ?? '') ?: null,
                    'offer_id'  => $offer?->id,
                    'pipeline'  => $pipeline,
                    'is_demo'   => false,
                    'is_active' => true,
                    'opened_at' => null,
                ]
            );
        }

        Transaction::create([
            'person_id'            => $person->id,
            'trading_account_id'   => $tradingAcc?->id,
            'mtr_transaction_uuid' => $mtrUuid,
            'type'                 => 'WITHDRAWAL',
            'amount_cents'         => $amountCents,
            'currency'             => strtoupper($financials['currency'] ?? 'USD'),
            'status'               => 'DONE',
            'gateway_name'         => $gatewayInfo['name'] ?? null,
            'remark'               => $raw['remark'] ?? null,
            'occurred_at'          => \Carbon\Carbon::parse($raw['created'] ?? now())->toIso8601String(),
            'synced_at'            => now()->toIso8601String(),
            'pipeline'             => $pipeline,
            'category'             => $category,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function trackDate(?string $dateStr, array &$stats): void
    {
        if (!$dateStr) {
            return;
        }

        try {
            $date = \Carbon\Carbon::parse($dateStr)->toDateString();
        } catch (\Throwable) {
            return;
        }

        if ($stats['earliest_date'] === null || $date < $stats['earliest_date']) {
            $stats['earliest_date'] = $date;
        }

        if ($stats['latest_date'] === null || $date > $stats['latest_date']) {
            $stats['latest_date'] = $date;
        }
    }
}
