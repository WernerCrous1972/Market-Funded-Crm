<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiDraftResource\Pages;

use App\Filament\Resources\AiDraftResource;
use App\Models\AiDraft;
use App\Services\AI\ComplianceAgent;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class EditAiDraft extends EditRecord
{
    protected static string $resource = AiDraftResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $edited = (string) ($data['final_text'] ?? '');

        /** @var AiDraft $draft */
        $draft = $this->getRecord();

        // If the human edited the AI's draft, re-run compliance against the
        // edited text. Hard flags block the save outright — a human cannot
        // edit a regulatory violation through the review gate.
        if (trim($edited) !== '' && $edited !== (string) $draft->draft_text) {
            $pipelineHint = $this->resolvePipelineHint($draft);
            $check = app(ComplianceAgent::class)->check($draft, $pipelineHint, $edited);

            if (! $check->passed) {
                $hardFlags = collect($check->flags ?? [])
                    ->where('severity', 'hard')
                    ->pluck('rule')
                    ->implode(', ');

                Notification::make()
                    ->title('Edit blocked by compliance')
                    ->body('Hard flag(s): ' . ($hardFlags ?: 'unspecified') . '. The edit was NOT saved. Revise the message and try again.')
                    ->danger()
                    ->persistent()
                    ->send();

                throw new Halt();
            }
        }

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

    private function resolvePipelineHint(AiDraft $draft): ?string
    {
        $metric = $draft->person?->metrics;
        if (! $metric) {
            return null;
        }
        if ($metric->has_markets ?? false) return 'MFU_MARKETS';
        if ($metric->has_capital ?? false) return 'MFU_CAPITAL';
        if ($metric->has_academy ?? false) return 'MFU_ACADEMY';
        return null;
    }
}
