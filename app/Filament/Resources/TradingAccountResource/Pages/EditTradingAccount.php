<?php

namespace App\Filament\Resources\TradingAccountResource\Pages;

use App\Filament\Resources\TradingAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTradingAccount extends EditRecord
{
    protected static string $resource = TradingAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
