<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutreachInboundMessageResource\Pages;

use App\Filament\Resources\OutreachInboundMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListOutreachInboundMessages extends ListRecords
{
    protected static string $resource = OutreachInboundMessageResource::class;

    protected function getHeaderActions(): array
    {
        return []; // Read-only.
    }
}
