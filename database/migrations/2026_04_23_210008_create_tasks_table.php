<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('person_id')->index();
            $table->uuid('assigned_to_user_id')->index();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('priority', 10)->default('MEDIUM')->comment('LOW, MEDIUM, HIGH, URGENT');
            $table->timestampsTz();

            $table->index('due_at');
            $table->index('priority');

            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->foreign('assigned_to_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
