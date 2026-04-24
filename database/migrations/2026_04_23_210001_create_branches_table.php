<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('mtr_branch_uuid', 64)->unique();
            $table->string('name', 100);
            $table->boolean('is_included')->default(false)->comment('True for Market Funded & QuickTrade only');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
