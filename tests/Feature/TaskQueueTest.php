<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\Person;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Task model', function () {

    it('resolves explicit assignee when provided', function () {
        $person = Person::factory()->create(['account_manager' => null]);
        $user   = User::factory()->create();

        $result = Task::resolveAssignee($person, $user->id);

        expect($result['user_id'])->toBe($user->id);
        expect($result['auto_assigned'])->toBeFalse();
    });

    it('auto-assigns to account manager by name when no explicit user given', function () {
        $user   = User::factory()->create(['name' => 'Sarah Dlamini']);
        $person = Person::factory()->create(['account_manager' => 'Sarah Dlamini']);

        $result = Task::resolveAssignee($person, null);

        expect($result['user_id'])->toBe($user->id);
        expect($result['auto_assigned'])->toBeTrue();
    });

    it('auto-assigns to account manager by email', function () {
        $user   = User::factory()->create(['email' => 'sarah@market-funded.com']);
        $person = Person::factory()->create(['account_manager' => 'sarah@market-funded.com']);

        $result = Task::resolveAssignee($person, null);

        expect($result['user_id'])->toBe($user->id);
        expect($result['auto_assigned'])->toBeTrue();
    });

    it('returns null user_id when no account manager and no explicit user', function () {
        $person = Person::factory()->create(['account_manager' => null]);

        $result = Task::resolveAssignee($person, null);

        expect($result['user_id'])->toBeNull();
        expect($result['auto_assigned'])->toBeFalse();
    });

    it('explicit user overrides account manager', function () {
        $manager = User::factory()->create(['name' => 'Sarah Dlamini']);
        $other   = User::factory()->create(['name' => 'John Doe']);
        $person  = Person::factory()->create(['account_manager' => 'Sarah Dlamini']);

        // Explicit user provided → should use that, not the account manager
        $result = Task::resolveAssignee($person, $other->id);

        expect($result['user_id'])->toBe($other->id);
        expect($result['auto_assigned'])->toBeFalse();
    });

    it('marks task complete and logs activity', function () {
        $person = Person::factory()->create();
        $user   = User::factory()->create();
        $task   = Task::factory()->create([
            'person_id'           => $person->id,
            'assigned_to_user_id' => $user->id,
            'completed_at'        => null,
        ]);

        $task->markComplete($user->id);

        expect($task->fresh()->completed_at)->not->toBeNull();
        expect(Activity::where('person_id', $person->id)
            ->where('type', Activity::TYPE_TASK_COMPLETED)
            ->exists()
        )->toBeTrue();
    });

    it('is_overdue returns true for past due tasks', function () {
        $task = Task::factory()->make([
            'due_at'       => now()->subDay(),
            'completed_at' => null,
        ]);
        expect($task->is_overdue)->toBeTrue();
    });

    it('is_overdue returns false for completed tasks', function () {
        $task = Task::factory()->make([
            'due_at'       => now()->subDay(),
            'completed_at' => now(),
        ]);
        expect($task->is_overdue)->toBeFalse();
    });

    it('is_due_today returns true for tasks due today', function () {
        // Use noon today (stable regardless of what time tests run — avoids UTC midnight crossing)
        $task = Task::factory()->make([
            'due_at'       => today()->setHour(12),
            'completed_at' => null,
        ]);
        expect($task->is_due_today)->toBeTrue();
    });

    it('scope overdue returns only pending past-due tasks', function () {
        $person = Person::factory()->create();
        $user   = User::factory()->create();

        Task::factory()->create([
            'person_id'           => $person->id,
            'assigned_to_user_id' => $user->id,
            'due_at'              => now()->subDay(),
            'completed_at'        => null,
        ]);

        Task::factory()->create([
            'person_id'           => $person->id,
            'assigned_to_user_id' => $user->id,
            'due_at'              => now()->subDay(),
            'completed_at'        => now(), // completed — should NOT appear
        ]);

        Task::factory()->create([
            'person_id'           => $person->id,
            'assigned_to_user_id' => $user->id,
            'due_at'              => now()->addDay(), // future — should NOT appear
            'completed_at'        => null,
        ]);

        expect(Task::overdue()->count())->toBe(1);
    });

});

describe('MyTasksPage', function () {

    beforeEach(function () {
        $user = User::factory()->create(['role' => 'ADMIN']);
        $this->actingAs($user);
    });

    it('my tasks page loads', function () {
        $this->get('/admin/my-tasks')->assertOk();
    });

    it('my tasks page loads for sales agent', function () {
        $agent = User::factory()->create(['role' => 'SALES_AGENT']);
        $this->actingAs($agent);
        $this->get('/admin/my-tasks')->assertOk();
    });

});
