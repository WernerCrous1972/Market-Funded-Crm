<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiDraftResource\Pages;

use App\Filament\Resources\AiDraftResource;
use Filament\Resources\Pages\ListRecords;

class ListAiDrafts extends ListRecords
{
    protected static string $resource = AiDraftResource::class;

    protected function getHeaderActions(): array
    {
        return []; // Drafts are created via the orchestrator, not via "Create"
    }
}
