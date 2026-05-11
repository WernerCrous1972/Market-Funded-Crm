<?php

declare(strict_types=1);

use App\Filament\Resources\PersonResource;
use App\Models\Branch;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

describe('Phase C — permission enforcement', function () {

    // ── Person.branch_id / account_manager_user_id columns ───────────────────

    it('Person model accepts branch_id and account_manager_user_id', function () {
        $branch = Branch::factory()->create(['is_included' => true]);
        $user   = User::factory()->create();
        $person = Person::factory()->create([
            'branch_id'               => $branch->id,
            'account_manager_user_id' => $user->id,
        ]);

        expect($person->fresh()->branch_id)->toBe($branch->id);
        expect($person->fresh()->account_manager_user_id)->toBe($user->id);
    });

    it('Person.branchModel() relation resolves correctly', function () {
        $branch = Branch::factory()->create(['is_included' => true]);
        $person = Person::factory()->create(['branch_id' => $branch->id]);

        expect($person->branchModel->id)->toBe($branch->id);
    });

    it('Person.accountManager() relation resolves correctly', function () {
        $user   = User::factory()->create();
        $person = Person::factory()->create(['account_manager_user_id' => $user->id]);

        expect($person->accountManager->id)->toBe($user->id);
    });

    // ── getEloquentQuery — super admin ────────────────────────────────────────

    it('super admin sees all people regardless of branch', function () {
        $admin  = User::factory()->create(['is_super_admin' => true]);
        $branch = Branch::factory()->create(['is_included' => true]);

        Person::factory()->count(3)->create(['branch_id' => $branch->id]);
        Person::factory()->create(['branch_id' => null]); // no branch

        $this->actingAs($admin);

        expect(PersonResource::getEloquentQuery()->count())->toBe(4);
    });

    // ── getEloquentQuery — branch-scoped user ─────────────────────────────────

    it('branch-scoped user only sees people from their branches', function () {
        $user    = User::factory()->create(['is_super_admin' => false, 'assigned_only' => false]);
        $branch1 = Branch::factory()->create(['is_included' => true]);
        $branch2 = Branch::factory()->create(['is_included' => true]);

        DB::table('user_branch_access')->insert([
            'user_id'    => $user->id,
            'branch_id'  => $branch1->id,
            'granted_at' => now(),
        ]);

        Person::factory()->count(2)->create(['branch_id' => $branch1->id]);
        Person::factory()->count(3)->create(['branch_id' => $branch2->id]); // not accessible
        Person::factory()->create(['branch_id' => null]); // null = invisible

        $this->actingAs($user);

        expect(PersonResource::getEloquentQuery()->count())->toBe(2);
    });

    it('branch-scoped user with no branches sees zero people', function () {
        $user   = User::factory()->create(['is_super_admin' => false, 'assigned_only' => false]);
        $branch = Branch::factory()->create(['is_included' => true]);

        Person::factory()->count(3)->create(['branch_id' => $branch->id]);

        $this->actingAs($user);

        expect(PersonResource::getEloquentQuery()->count())->toBe(0);
    });

    // ── getEloquentQuery — assigned_only ──────────────────────────────────────

    it('assigned_only user only sees their own contacts within accessible branches', function () {
        $user   = User::factory()->create(['is_super_admin' => false, 'assigned_only' => true]);
        $branch = Branch::factory()->create(['is_included' => true]);

        DB::table('user_branch_access')->insert([
            'user_id'    => $user->id,
            'branch_id'  => $branch->id,
            'granted_at' => now(),
            'granted_by' => $user->id,
        ]);

        Person::factory()->create(['branch_id' => $branch->id, 'account_manager_user_id' => $user->id]);
        Person::factory()->create(['branch_id' => $branch->id, 'account_manager_user_id' => null]);

        $this->actingAs($user);

        expect(PersonResource::getEloquentQuery()->count())->toBe(1);
    });

    it('assigned_only user with no branch access sees nothing', function () {
        $user   = User::factory()->create(['is_super_admin' => false, 'assigned_only' => true]);
        $branch = Branch::factory()->create(['is_included' => true]);

        Person::factory()->create(['branch_id' => $branch->id, 'account_manager_user_id' => $user->id]);

        $this->actingAs($user);

        expect(PersonResource::getEloquentQuery()->count())->toBe(0);
    });

    // ── PersonPolicy ──────────────────────────────────────────────────────────

    it('PersonPolicy::view returns true for super admin', function () {
        $admin  = User::factory()->create(['is_super_admin' => true]);
        $branch = Branch::factory()->create(['is_included' => true]);
        $person = Person::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($admin);

        expect(Gate::allows('view', $person))->toBeTrue();
    });

    it('PersonPolicy::view returns true for user with branch access', function () {
        $user   = User::factory()->create(['is_super_admin' => false, 'assigned_only' => false]);
        $branch = Branch::factory()->create(['is_included' => true]);
        $person = Person::factory()->create(['branch_id' => $branch->id]);

        DB::table('user_branch_access')->insert([
            'user_id'    => $user->id,
            'branch_id'  => $branch->id,
            'granted_at' => now(),
        ]);

        $this->actingAs($user);

        expect(Gate::allows('view', $person))->toBeTrue();
    });

    it('PersonPolicy::view returns false for user without branch access', function () {
        $user   = User::factory()->create(['is_super_admin' => false, 'assigned_only' => false]);
        $branch = Branch::factory()->create(['is_included' => true]);
        $person = Person::factory()->create(['branch_id' => $branch->id]);
        // no user_branch_access row

        $this->actingAs($user);

        expect(Gate::allows('view', $person))->toBeFalse();
    });

    it('PersonPolicy::view returns false for null branch_id', function () {
        $user   = User::factory()->create(['is_super_admin' => false, 'assigned_only' => false]);
        $branch = Branch::factory()->create(['is_included' => true]);
        $person = Person::factory()->create(['branch_id' => null]);

        DB::table('user_branch_access')->insert([
            'user_id'    => $user->id,
            'branch_id'  => $branch->id,
            'granted_at' => now(),
        ]);

        $this->actingAs($user);

        expect(Gate::allows('view', $person))->toBeFalse();
    });

    it('PersonPolicy::view returns true for assigned_only user on their own contact', function () {
        $user   = User::factory()->create(['is_super_admin' => false, 'assigned_only' => true]);
        $branch = Branch::factory()->create(['is_included' => true]);
        $person = Person::factory()->create([
            'branch_id'               => $branch->id,
            'account_manager_user_id' => $user->id,
        ]);

        $this->actingAs($user);

        expect(Gate::allows('view', $person))->toBeTrue();
    });

    it('PersonPolicy::view returns false for assigned_only user on someone else\'s contact', function () {
        $user    = User::factory()->create(['is_super_admin' => false, 'assigned_only' => true]);
        $other   = User::factory()->create();
        $branch  = Branch::factory()->create(['is_included' => true]);
        $person  = Person::factory()->create([
            'branch_id'               => $branch->id,
            'account_manager_user_id' => $other->id,
        ]);

        $this->actingAs($user);

        expect(Gate::allows('view', $person))->toBeFalse();
    });

    // ── Person factory ────────────────────────────────────────────────────────

    it('Person factory attaches a draft-ready branch but leaves account manager null', function () {
        $person = Person::factory()->create();
        expect($person->branch_id)->not->toBeNull();
        expect($person->branchModel)->not->toBeNull();
        expect($person->branchModel->outreach_enabled)->toBeTrue();
        expect($person->account_manager_user_id)->toBeNull();
    });

    it('Person factory withoutBranch() state leaves branch_id null', function () {
        $person = Person::factory()->withoutBranch()->create();
        expect($person->branch_id)->toBeNull();
    });
});
