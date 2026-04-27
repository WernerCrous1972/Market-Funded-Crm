<?php

declare(strict_types=1);

namespace App\Livewire;

use Filament\Notifications\Notification;
use Livewire\Component;

class EchoAlertListener extends Component
{
    public function onDepositReceived(array $data): void
    {
        $name   = $data['personName'] ?? 'Unknown';
        $amount = $data['amountUsd']  ?? '?';

        Notification::make()
            ->title('Deposit Received')
            ->body("{$name} — {$amount}")
            ->success()
            ->duration(8000)
            ->send();
    }

    public function onLeadConverted(array $data): void
    {
        $name = $data['personName'] ?? 'Unknown';

        Notification::make()
            ->title('Lead Converted!')
            ->body("{$name} just became a client")
            ->success()
            ->duration(8000)
            ->send();
    }

    public function onWithdrawalLarge(array $data): void
    {
        $name   = $data['personName'] ?? 'Unknown';
        $amount = $data['amountUsd']  ?? '?';

        Notification::make()
            ->title('Large Withdrawal')
            ->body("{$name} — {$amount}")
            ->warning()
            ->duration(8000)
            ->send();
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.echo-alert-listener');
    }
}
