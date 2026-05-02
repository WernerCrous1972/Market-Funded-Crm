<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\PermissionAuditLog;
use App\Models\PermissionTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

describe('Phase B — permissions', function () {

    // ── Templates ────────────────────────────────────────────────────────────

    it('seeds 7 permission templates', function () {
        expect(PermissionTemplate::count())->toBe(7);
    });

    it('templates have correct display order', function () {
        $names = PermissionTemplate::orderBy('display_order')->pluck('name')->toArray();
        expect($names)->toBe([
            'Super Admin',
            'Admin',
            'Broker Partner',
            'Master IB / IB / Sales Manager',
            'Sales Agent (assigned only)',
            'Sales Agent (full branch view)',
            'Viewer',
        ]);
    });

    it('every template has all 14 toggle keys', function () {
        $expected = array_flip(PermissionTemplate::TOGGLE_COLUMNS);
        PermissionTemplate::all()->each(function (PermissionTemplate $t) use ($expected) {
            $missing = array_diff_key($expected, $t->toggles ?? []);
            expect($missing)->toBeEmpty("Template '{$t->name}' is missing: " . implode(', ', array_keys($missing)));
        });
    });

    it('super admin template has is_super_admin and all can_* toggles true', function () {
        $template     = PermissionTemplate::where('name', 'Super Admin')->firstOrFail();
        $mustBeTrue   = array_filter(
            PermissionTemplate::TOGGLE_COLUMNS,
            fn (string $col) => $col !== 'assigned_only', // false = sees ALL clients (more permissive)
        );
        foreach ($mustBeTrue as $key) {
            expect($template->toggles[$key])->toBeTrue("Expected {$key} to be true in Super Admin template");
        }
        // assigned_only: false is correct for Super Admin — means NO restriction to assigned clients
        expect($template->toggles['assigned_only'])->toBeFalse();
    });

    it('viewer template has all action toggles false', function () {
        $template = PermissionTemplate::where('name', 'Viewer')->firstOrFail();
        $actionToggles = [
            'can_make_notes', 'can_send_whatsapp', 'can_send_email',
            'can_create_email_campaigns', 'can_edit_clients', 'can_assign_clients',
            'can_create_tasks', 'can_assign_tasks_to_others', 'can_export',
        ];
        foreach ($actionToggles as $toggle) {
            expect($template->toggles[$toggle])->toBeFalse("Expected {$toggle} to be false in Viewer template");
        }
    });

    it('safeToggles() strips is_super_admin', function () {
        $template = PermissionTemplate::where('name', 'Super Admin')->firstOrFail();
        expect($template->safeToggles())->not->toHaveKey('is_super_admin');
    });

    // ── Gate super admin bypass ───────────────────────────────────────────────

    it('super admin passes every Gate check', function () {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin);

        expect(Gate::allows('edit-clients'))->toBeTrue();
        expect(Gate::allows('some-nonexistent-ability'))->toBeTrue();
    });

    it('non-super-admin does not get blanket Gate bypass', function () {
        $user = User::factory()->create(['is_super_admin' => false]);
        $this->actingAs($user);

        // No policy defined for this ability — Gate returns false, not true
        expect(Gate::allows('some-nonexistent-ability'))->toBeFalse();
    });

    // ── Observer audit log ────────────────────────────────────────────────────

    it('writes TOGGLE_CHANGED audit log when a permission column is updated', function () {
        $actor  = User::factory()->create(['is_super_admin' => true]);
        $target = User::factory()->create(['can_export' => false]);
        $this->actingAs($actor);

        $target->update(['can_export' => true]);

        $log = PermissionAuditLog::where('target_user_id', $target->id)
            ->where('change_type', PermissionAuditLog::TYPE_TOGGLE_CHANGED)
            ->latest('created_at')
            ->first();

        expect($log)->not->toBeNull();
        expect($log->changes['field'])->toBe('can_export');
        expect($log->changes['from'])->toBeFalse();
        expect($log->changes['to'])->toBeTrue();
        expect($log->actor_user_id)->toBe($actor->id);
    });

    it('writes SUPER_ADMIN_GRANTED when is_super_admin flips to true', function () {
        $actor  = User::factory()->create(['is_super_admin' => true]);
        $target = User::factory()->create(['is_super_admin' => false]);
        $this->actingAs($actor);

        $target->update(['is_super_admin' => true]);

        $log = PermissionAuditLog::where('target_user_id', $target->id)
            ->where('change_type', PermissionAuditLog::TYPE_SUPER_ADMIN_GRANTED)
            ->first();

        expect($log)->not->toBeNull();
        expect($log->changes['field'])->toBe('is_super_admin');
    });

    it('writes SUPER_ADMIN_REVOKED when is_super_admin flips to false', function () {
        $actor  = User::factory()->create(['is_super_admin' => true]);
        $target = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($actor);

        $target->update(['is_super_admin' => false]);

        $log = PermissionAuditLog::where('target_user_id', $target->id)
            ->where('change_type', PermissionAuditLog::TYPE_SUPER_ADMIN_REVOKED)
            ->first();

        expect($log)->not->toBeNull();
    });

    it('does not write audit log when non-permission column changes', function () {
        $target = User::factory()->create();
        $before = PermissionAuditLog::where('target_user_id', $target->id)->count();

        $target->update(['name' => 'New Name']);

        expect(PermissionAuditLog::where('target_user_id', $target->id)->count())->toBe($before);
    });

    // ── Branch access pivot ───────────────────────────────────────────────────

    it('UserResource::syncBranchAccess writes BRANCH_GRANTED audit entries', function () {
        $actor  = User::factory()->create(['is_super_admin' => true]);
        $target = User::factory()->create();
        $branch = Branch::factory()->create(['is_included' => true]);

        \App\Filament\Resources\UserResource::syncBranchAccess($target, [$branch->id], $actor->id);

        expect(DB::table('user_branch_access')
            ->where('user_id', $target->id)
            ->where('branch_id', $branch->id)
            ->exists()
        )->toBeTrue();

        $log = PermissionAuditLog::where('target_user_id', $target->id)
            ->where('change_type', PermissionAuditLog::TYPE_BRANCH_GRANTED)
            ->first();

        expect($log)->not->toBeNull();
        expect($log->changes['branch_id'])->toBe($branch->id);
    });

    it('UserResource::syncBranchAccess writes BRANCH_REVOKED when branch is removed', function () {
        $actor  = User::factory()->create(['is_super_admin' => true]);
        $target = User::factory()->create();
        $branch = Branch::factory()->create(['is_included' => true]);

        // First grant
        DB::table('user_branch_access')->insert([
            'user_id' => $target->id, 'branch_id' => $branch->id,
            'granted_at' => now(), 'granted_by' => $actor->id,
        ]);

        // Now revoke by syncing with empty array
        \App\Filament\Resources\UserResource::syncBranchAccess($target, [], $actor->id);

        expect(DB::table('user_branch_access')
            ->where('user_id', $target->id)->exists()
        )->toBeFalse();

        $log = PermissionAuditLog::where('target_user_id', $target->id)
            ->where('change_type', PermissionAuditLog::TYPE_BRANCH_REVOKED)
            ->first();

        expect($log)->not->toBeNull();
    });

    // ── PermissionAuditLog model ──────────────────────────────────────────────

    it('PermissionAuditLog::record() defaults actor to auth user', function () {
        $actor  = User::factory()->create(['is_super_admin' => true]);
        $target = User::factory()->create();
        $this->actingAs($actor);

        $log = PermissionAuditLog::record(
            targetUserId: $target->id,
            changeType: PermissionAuditLog::TYPE_TOGGLE_CHANGED,
            changes: ['field' => 'can_export', 'from' => false, 'to' => true],
        );

        expect($log->actor_user_id)->toBe($actor->id);
    });

    it('PermissionAuditLog has no updated_at', function () {
        expect(PermissionAuditLog::UPDATED_AT)->toBeNull();
    });

    // ── User model ────────────────────────────────────────────────────────────

    it('User has all 14 permission columns castable to boolean', function () {
        $user = User::factory()->create();
        foreach (PermissionTemplate::TOGGLE_COLUMNS as $column) {
            expect($user->{$column})->toBeBool("Column {$column} should cast to bool");
        }
    });

    it('hasBranchAccess() returns true for super admin regardless of pivot', function () {
        $user   = User::factory()->create(['is_super_admin' => true]);
        $branch = Branch::factory()->create(['is_included' => true]);

        expect($user->hasBranchAccess($branch->id))->toBeTrue();
    });

    it('hasBranchAccess() returns false when no pivot row exists', function () {
        $user   = User::factory()->create(['is_super_admin' => false]);
        $branch = Branch::factory()->create(['is_included' => true]);

        expect($user->hasBranchAccess($branch->id))->toBeFalse();
    });
});
