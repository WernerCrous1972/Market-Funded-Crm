<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    private array $pendingBranchIds  = [];
    private ?string $pendingTemplateId = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => ! $this->record->is_super_admin),
        ];
    }

    /**
     * Load existing branch access into the virtual `branch_ids` form field
     * so the CheckboxList reflects the current state on page load.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['branch_ids'] = DB::table('user_branch_access')
            ->where('user_id', $this->record->id)
            ->pluck('branch_id')
            ->toArray();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingBranchIds  = $data['branch_ids'] ?? [];
        $this->pendingTemplateId = $data['_applied_template_id'] ?? null;

        unset($data['branch_ids'], $data['_applied_template_id']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var User $user */
        $user    = $this->record;
        $actorId = auth()->id();

        UserResource::syncBranchAccess($user, $this->pendingBranchIds, $actorId);
        UserResource::logTemplateApplication($user, $this->pendingTemplateId, $actorId);
    }
}
