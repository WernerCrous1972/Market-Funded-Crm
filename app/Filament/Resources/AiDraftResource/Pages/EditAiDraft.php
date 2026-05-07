<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiDraftResource\Pages;

use App\Filament\Resources\AiDraftResource;
use App\Models\AiDraft;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAiDraft extends EditRecord
{
    protected static string $resource = AiDraftResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Save edits to final_text only — never let the agent retroactively
        // change draft_text, prompt_hash, model_used, etc.
        return [
            'final_text' => $data['final_text'] ?? null,
        ];
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->title('Draft updated')
            ->body('Click "Approve & send" from the list to dispatch.')
            ->success();
    }
}
