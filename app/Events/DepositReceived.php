<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Person;
use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a new deposit transaction is inserted by SyncDepositsJob.
 * Broadcasts to the assigned account manager via the crm-alerts channel.
 */
class DepositReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $personId;
    public readonly string $personName;
    public readonly string $personEmail;
    public readonly ?string $accountManager;
    public readonly int $amountCents;
    public readonly string $amountUsd;
    public readonly string $pipeline;
    public readonly string $category;
    public readonly string $transactionId;

    public function __construct(Person $person, Transaction $transaction)
    {
        $this->personId       = $person->id;
        $this->personName     = $person->full_name;
        $this->personEmail    = $person->email;
        $this->accountManager = $person->account_manager;
        $this->amountCents    = $transaction->amount_cents;
        $this->amountUsd      = '$' . number_format($transaction->amount_cents / 100, 2);
        $this->pipeline       = $transaction->pipeline ?? 'MFU_MARKETS';
        $this->category       = $transaction->category ?? 'EXTERNAL_DEPOSIT';
        $this->transactionId  = $transaction->id;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('crm-alerts'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deposit.received';
    }
}
