<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('person_id')->index();
            $table->string('mtr_account_uuid', 64)->unique()->comment('The MTR uuid field');
            $table->string('mtr_login', 20)->nullable()->comment('MTR login number e.g. 719188');
            $table->uuid('offer_id')->nullable()->index();
            $table->string('pipeline', 20)->comment('MFU_CAPITAL, MFU_ACADEMY, MFU_MARKETS, UNCLASSIFIED');
            $table->boolean('is_demo')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('opened_at')->nullable()->comment('From MTR created field');
            $table->timestampsTz();

            $table->index('pipeline');
            $table->index('is_active');

            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->foreign('offer_id')->references('id')->on('offers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_accounts');
    }
};
