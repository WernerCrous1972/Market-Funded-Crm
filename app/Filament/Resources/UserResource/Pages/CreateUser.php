<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    private array $pendingBranchIds  = [];
    private ?string $pendingTemplateId = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingBranchIds  = $data['branch_ids'] ?? [];
        $this->pendingTemplateId = $data['_applied_template_id'] ?? null;

        unset($data['branch_ids'], $data['_applied_template_id']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user    = $this->record;
        $actorId = auth()->id();

        UserResource::syncBranchAccess($user, $this->pendingBranchIds, $actorId);
        UserResource::logTemplateApplication($user, $this->pendingTemplateId, $actorId);
    }
}
