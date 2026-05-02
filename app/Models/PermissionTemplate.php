<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PermissionTemplate extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** Templates stamp values at user creation — editing a template does NOT update existing users. */
    public const BRANCH_ACCESS_ALL          = 'ALL';
    public const BRANCH_ACCESS_ONE          = 'ONE';
    public const BRANCH_ACCESS_CONFIGURABLE = 'CONFIGURABLE';

    /** The 14 permission toggle column names, in display order. */
    public const TOGGLE_COLUMNS = [
        'is_super_admin',
        'assigned_only',
        'can_view_client_financials',
        'can_view_branch_financials',
        'can_view_health_scores',
        'can_make_notes',
        'can_send_whatsapp',
        'can_send_email',
        'can_create_email_campaigns',
        'can_edit_clients',
        'can_assign_clients',
        'can_create_tasks',
        'can_assign_tasks_to_others',
        'can_export',
    ];

    protected $fillable = [
        'name',
        'description',
        'display_order',
        'branch_access_default',
        'toggles',
    ];

    protected function casts(): array
    {
        return [
            'toggles'       => 'array',
            'display_order' => 'integer',
        ];
    }

    /**
     * Returns only the 13 non-super-admin toggles from this template.
     * Safe to apply via the template picker regardless of the actor's role.
     */
    public function safeToggles(): array
    {
        return collect($this->toggles ?? [])
            ->except('is_super_admin')
            ->all();
    }
}
