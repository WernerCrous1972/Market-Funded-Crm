<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutreachTemplateResource\Pages;

use App\Filament\Resources\OutreachTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOutreachTemplates extends ListRecords
{
    protected static string $resource = OutreachTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
