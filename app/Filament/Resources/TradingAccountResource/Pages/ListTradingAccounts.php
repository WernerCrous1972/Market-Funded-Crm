<?php

declare(strict_types=1);

namespace App\Filament\Resources\TradingAccountResource\Pages;

use App\Filament\Resources\TradingAccountResource;
use Filament\Resources\Pages\ListRecords;

class ListTradingAccounts extends ListRecords
{
    protected static string $resource = TradingAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
