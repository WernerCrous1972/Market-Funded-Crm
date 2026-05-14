<?php

declare(strict_types=1);

namespace App\Jobs\Sync;

use App\Events\DepositReceived;
use App\Models\Activity;
use App\Models\Offer;
use App\Models\Person;
use App\Models\TradingAccount;
use App\Models\Transaction;
use App\Services\MatchTrader\Client;
use App\Services\Normalizer\EmailNormalizer;
use App\Services\Pipeline\Classifier;
use App\Services\Transaction\CategoryClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncDepositsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;
    public int $timeout = 3600;

    public function __construct(
        public readonly bool $dryRun = false,
        public readonly ?string $since = null,
    ) {}

    public function handle(Client $mtr): void
    {
        Log::info('SyncDepositsJob: starting', ['since' => $this->since]);

        $excludedGateways = array_map('strtolower', (array) config('matchtrader.excluded_gateways'));
        $excludedRemarks  = array_map('strtolower', (array) config('matchtrader.excluded_remarks'));
        $validStatuses    = array_map('strtolower', (array) config('matchtrader.valid_transaction_statuses'));

        // Build branch uuid → is_included lookup for branch filtering
        $includedBranchUuids = \App\Models\Branch::where('is_included', true)
            ->pluck('mtr_branch_uuid')
            ->flip()
            ->toArray();

        // Build offer lookup for pipeline classification
        $offerLookup = Offer::all()->keyBy('mtr_offer_uuid');
        $propUuids   = Offer::where('is_prop_challenge', true)->pluck('mtr_offer_uuid')->toArray();
        Classifier::setPropOfferUuids($propUuids);

        // Name → User ID lookup for per-transaction account_manager attribution.
        // We attribute each transaction to whoever the MTR API records as the
        // accountManager on THAT transaction row at sync time. MTR snapshots
        // this historically — verified live 2026-05-14.
        $userLookup = \App\Models\User::pluck('id', 'name')->all();

        $stats = [
            'total'   => 0,
            'skipped' => 0,
            'created' => 0,
            'errors'  => 0,
        ];

        foreach ($mtr->allDeposits($this->since) as $raw) {
            $stats['total']++;

            try {
                // Actual API structure uses accountInfo and paymentRequestInfo
                $accountInfo   = $raw['accountInfo'] ?? [];
                $paymentInfo   = $raw['paymentRequestInfo'] ?? [];
                $financials    = $paymentInfo['financialDetails'] ?? [];
                $gatewayInfo   = $paymentInfo['paymentGatewayDetails'] ?? [];

                // ── Branch filter ──────────────────────────────────────────
                $branchUuid = $accountInfo['branchUuid'] ?? null;
                if ($branchUuid && ! array_key_exists($branchUuid, $includedBranchUuids)) {
                    $stats['skipped']++;
                    continue;
                }

                // ── Status filter ──────────────────────────────────────────
                $status = strtolower($financials['status'] ?? '');
                if (! in_array($status, $validStatuses, true)) {
                    $stats['skipped']++;
                    continue;
                }

                // ── Gateway filter ─────────────────────────────────────────
                $gateway = strtolower(trim($gatewayInfo['name'] ?? ''));
                if (in_array($gateway, $excludedGateways, true)) {
                    $stats['skipped']++;
                    continue;
                }

                // ── Remark filter ──────────────────────────────────────────
                $remark = strtolower(trim($raw['remark'] ?? ''));
                foreach ($excludedRemarks as $excluded) {
                    if (str_contains($remark, $excluded)) {
                        $stats['skipped']++;
                        continue 2;
                    }
                }

                // ── Find person ────────────────────────────────────────────
                $rawEmail = $accountInfo['email'] ?? '';
                if (! EmailNormalizer::isValid($rawEmail)) {
                    $stats['skipped']++;
                    continue;
                }
                $email  = EmailNormalizer::normalize($rawEmail);
                $person = Person::where('email', $email)->first();

                if (! $person) {
                    $stats['skipped']++;
                    Log::debug("SyncDepositsJob: no person for {$email}");
                    continue;
                }

                // ── Upsert trading account from deposit data ───────────────
                // The /v1/accounts endpoint doesn't return trading account data;
                // accountInfo.tradingAccount is the authoritative source for login + offer.
                $taInfo    = $accountInfo['tradingAccount'] ?? [];
                $taUuid    = $taInfo['uuid'] ?? null;
                $mtrLogin  = (string) ($taInfo['login'] ?? '');
                $offerUuid = $taInfo['offerUuid'] ?? null;
                $offer     = $offerUuid ? $offerLookup->get($offerUuid) : null;
                $pipeline  = $offer?->pipeline ?? Classifier::classify(
                    $offer?->name ?? $offerUuid,
                    $offerUuid
                );

                $tradingAcc = null;
                if ($taUuid && ! $this->dryRun) {
                    $tradingAcc = TradingAccount::updateOrCreate(
                        ['mtr_account_uuid' => $taUuid],
                        [
                            'person_id'  => $person->id,
                            'mtr_login'  => $mtrLogin ?: null,
                            'offer_id'   => $offer?->id,
                            'pipeline'   => $pipeline,
                            'is_demo'    => false,
                            'is_active'  => true,
                            'opened_at'  => null,
                        ]
                    );
                }

                $mtrUuid = $raw['uuid'] ?? null;
                if (! $mtrUuid) {
                    $stats['skipped']++;
                    continue;
                }

                $amountCents = (int) round((float) ($financials['amount'] ?? 0) * 100);

                if ($this->dryRun) {
                    Log::info("DRY-RUN deposit: {$email} amount={$amountCents} pipeline={$pipeline}");
                    $stats['created']++;
                    continue;
                }

                // ── Idempotent insert ──────────────────────────────────────
                $exists = Transaction::where('mtr_transaction_uuid', $mtrUuid)->exists();
                if ($exists) {
                    $stats['skipped']++;
                    continue;
                }

                $category = CategoryClassifier::classify(
                    type:        'DEPOSIT',
                    status:      'DONE',
                    gatewayName: $gatewayInfo['name'] ?? null,
                    offerName:   $offer?->name,
                );

                // Per-transaction historical account manager. Read directly
                // from the MTR API row — MTR snapshots this at transaction
                // time, so it's the correct attribution for sales reporting.
                $txMgrName = (function ($am) {
                    if (is_array($am)) return $am['name'] ?? null;
                    return $am ?: null;
                })($accountInfo['accountManager'] ?? null);
                $txMgrUserId = $txMgrName ? ($userLookup[$txMgrName] ?? null) : null;

                $transaction = Transaction::create([
                    'person_id'              => $person->id,
                    'account_manager_user_id'=> $txMgrUserId,
                    'trading_account_id'     => $tradingAcc?->id,
                    'mtr_transaction_uuid'   => $mtrUuid,
                    'type'                   => 'DEPOSIT',
                    'amount_cents'           => $amountCents,
                    'currency'               => strtoupper($financials['currency'] ?? 'USD'),
                    'status'                 => 'DONE',
                    'gateway_name'           => $gatewayInfo['name'] ?? null,
                    'offer_name'             => $offer?->name,
                    'remark'                 => $raw['remark'] ?? null,
                    'occurred_at'            => \Carbon\Carbon::parse($raw['created'] ?? now())->toIso8601String(),
                    'synced_at'              => now()->toIso8601String(),
                    'pipeline'               => $pipeline,
                    'category'               => $category,
                ]);

                $amountUsd = number_format($amountCents / 100, 2);
                Activity::record(
                    $person->id,
                    'DEPOSIT',
                    "Deposit of \${$amountUsd} via " . ($gatewayInfo['name'] ?? 'unknown'),
                    [
                        'amount_cents'   => $amountCents,
                        'currency'       => $transaction->currency,
                        'pipeline'       => $pipeline,
                        'transaction_id' => $transaction->id,
                    ],
                    occurredAt: $transaction->occurred_at,
                );

                broadcast(new DepositReceived($person, $transaction));

                $stats['created']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('SyncDepositsJob: error', [
                    'uuid'  => $raw['uuid'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('SyncDepositsJob: complete', $stats);
    }
}
