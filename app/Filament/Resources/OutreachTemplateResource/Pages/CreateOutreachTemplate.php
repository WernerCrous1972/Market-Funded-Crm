<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutreachTemplateResource\Pages;

use App\Filament\Resources\OutreachTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOutreachTemplate extends CreateRecord
{
    protected static string $resource = OutreachTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // New templates always start with autonomous_enabled = false regardless
        // of what the form said — admin opts in explicitly via Edit later.
        $data['autonomous_enabled'] = false;
        $data['created_by_user_id']  = auth()->id();
        return $data;
    }
}
