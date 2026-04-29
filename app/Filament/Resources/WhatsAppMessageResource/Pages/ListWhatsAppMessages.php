<?php

declare(strict_types=1);

namespace App\Filament\Resources\WhatsAppMessageResource\Pages;

use App\Filament\Resources\WhatsAppMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListWhatsAppMessages extends ListRecords
{
    protected static string $resource = WhatsAppMessageResource::class;
}
