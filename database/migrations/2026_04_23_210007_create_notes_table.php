<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('person_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('title', 255)->nullable();
            $table->text('body');
            $table->string('source', 20)->default('MANUAL')->comment('MANUAL, MTR_IMPORT, SYSTEM');
            $table->timestampsTz();

            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
