<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PermissionAuditLog;
use App\Models\PermissionTemplate;
use App\Models\User;

/**
 * Watches User model updates and writes a PermissionAuditLog entry for every
 * permission column that changed. Triggered on `updated` so `getChanges()` and
 * `getOriginal()` reflect the committed diff.
 */
class UserPermissionObserver
{
    public function updated(User $user): void
    {
        $changed = array_intersect_key(
            $user->getChanges(),
            array_flip(PermissionTemplate::TOGGLE_COLUMNS),
        );

        if (empty($changed)) {
            return;
        }

        $actorId = auth()->id();

        foreach ($changed as $field => $newValue) {
            $oldValue = $user->getOriginal($field);

            // is_super_admin gets its own semantic change_type
            if ($field === 'is_super_admin') {
                $changeType = $newValue
                    ? PermissionAuditLog::TYPE_SUPER_ADMIN_GRANTED
                    : PermissionAuditLog::TYPE_SUPER_ADMIN_REVOKED;
            } else {
                $changeType = PermissionAuditLog::TYPE_TOGGLE_CHANGED;
            }

            PermissionAuditLog::record(
                targetUserId: $user->id,
                changeType: $changeType,
                changes: [
                    'field' => $field,
                    'from'  => (bool) $oldValue,
                    'to'    => (bool) $newValue,
                ],
                actorUserId: $actorId,
            );
        }
    }
}
