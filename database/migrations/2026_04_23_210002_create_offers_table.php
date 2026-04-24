<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('mtr_offer_uuid', 64)->unique();
            $table->string('name', 255);
            $table->string('pipeline', 20)->comment('MFU_CAPITAL, MFU_ACADEMY, MFU_MARKETS, UNCLASSIFIED');
            $table->boolean('is_demo')->default(false);
            $table->boolean('is_prop_challenge')->default(false)->comment('True if UUID found in any prop challenge phase');
            $table->string('branch_uuid', 64)->nullable();
            $table->jsonb('raw_data')->nullable()->comment('Full MTR response for future reference');
            $table->timestampsTz();

            $table->index('pipeline');
            $table->index('is_prop_challenge');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
