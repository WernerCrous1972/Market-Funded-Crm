<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Who created the task (may differ from assignee)
            $table->uuid('created_by_user_id')->nullable()->after('assigned_to_user_id');
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            // Was this auto-assigned via account_manager field?
            $table->boolean('auto_assigned')->default(false)->after('created_by_user_id');

            // Task type for filtering/icons
            $table->string('task_type', 50)->default('GENERAL')->after('auto_assigned');
            // Values: GENERAL, FOLLOW_UP, CALL, DEPOSIT_FOLLOW_UP, WITHDRAWAL_QUERY, KYC, OTHER
        });

        DB::statement('CREATE INDEX tasks_assigned_to_due_idx ON tasks (assigned_to_user_id, due_at)');
        DB::statement('CREATE INDEX tasks_completed_at_idx ON tasks (completed_at)');
        DB::statement('CREATE INDEX tasks_person_id_idx ON tasks (person_id)');
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn(['created_by_user_id', 'auto_assigned', 'task_type']);
        });
    }
};
