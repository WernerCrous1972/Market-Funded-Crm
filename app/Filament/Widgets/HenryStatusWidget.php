<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\Henry\GatewayClient;
use App\Services\Notifications\TelegramNotifier;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Tiny three-stat widget showing whether Henry's gateway and the Telegram
 * notification path are alive. Visible to super admins only.
 */
class HenryStatusWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->is_super_admin === true;
    }

    protected function getStats(): array
    {
        $henry = app(GatewayClient::class);
        $tg    = app(TelegramNotifier::class);

        $henryStatus = $henry->status();
        $tgReachable = $tg->isReachable();

        return [
            Stat::make('Henry gateway', $this->labelFor($henryStatus))
                ->description($this->descriptionFor($henryStatus))
                ->color($this->colorFor($henryStatus))
                ->icon($this->iconFor($henryStatus)),

            Stat::make('Telegram bot', $tgReachable ? 'reachable' : 'unreachable')
                ->description($tgReachable
                    ? 'Outbound notifications working'
                    : 'CRM cannot send Telegram alerts')
                ->color($tgReachable ? 'success' : 'danger')
                ->icon($tgReachable ? 'heroicon-o-paper-airplane' : 'heroicon-o-exclamation-triangle'),

            Stat::make('AI ops', 'phase 4a')
                ->description('Outreach engine in development')
                ->color('gray')
                ->icon('heroicon-o-sparkles'),
        ];
    }

    private function labelFor(string $status): string
    {
        return match ($status) {
            'online'  => 'online',
            'offline' => 'offline',
            default   => 'not configured',
        };
    }

    private function descriptionFor(string $status): string
    {
        return match ($status) {
            'online'  => 'OpenClaw gateway responding',
            'offline' => 'Gateway not reachable on configured URL',
            default   => 'HENRY_GATEWAY_URL is missing from .env',
        };
    }

    private function colorFor(string $status): string
    {
        return match ($status) {
            'online'  => 'success',
            'offline' => 'danger',
            default   => 'gray',
        };
    }

    private function iconFor(string $status): string
    {
        return match ($status) {
            'online'  => 'heroicon-o-check-circle',
            'offline' => 'heroicon-o-x-circle',
            default   => 'heroicon-o-question-mark-circle',
        };
    }
}
