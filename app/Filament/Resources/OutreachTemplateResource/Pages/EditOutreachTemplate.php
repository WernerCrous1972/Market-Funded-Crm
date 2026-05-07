<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutreachTemplateResource\Pages;

use App\Filament\Resources\OutreachTemplateResource;
use App\Models\OutreachTemplate;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditOutreachTemplate extends EditRecord
{
    protected static string $resource = OutreachTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    /**
     * Confirmation gate when flipping autonomous_enabled from false → true.
     * Lives here (not in the form) because Filament forms don't have a
     * "before save" confirmation hook for individual fields cleanly. We
     * intercept on save and notify the admin if a flip happened.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var OutreachTemplate $record */
        $record = $this->record;
        $wasAuto = (bool) $record->autonomous_enabled;
        $nowAuto = (bool) ($data['autonomous_enabled'] ?? false);

        if (! $wasAuto && $nowAuto) {
            // Use a session flash so the next page render shows the warning.
            // We don't block the save — the admin already saw the form's red
            // help text. This is just a visible audit trail for them.
            session()->flash('autonomous_enabled_warning',
                "Autonomous send is now ON for template '{$record->name}'. " .
                'Cost ceilings + compliance gate + kill switch all still apply.');
        }

        return $data;
    }

    protected function getSavedNotification(): ?Notification
    {
        $msg = session('autonomous_enabled_warning');
        if ($msg) {
            session()->forget('autonomous_enabled_warning');
            return Notification::make()
                ->title('Template saved — autonomous send ENABLED')
                ->body($msg)
                ->warning()
                ->persistent();
        }
        return parent::getSavedNotification();
    }
}
