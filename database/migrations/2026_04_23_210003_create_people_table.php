<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 255)->unique()->comment('Always stored lowercase');
            $table->string('phone_e164', 20)->nullable()->index();
            $table->string('phone_country_code', 3)->nullable()->comment('ISO-2 e.g. ZA');
            $table->string('country', 3)->nullable()->comment('ISO-2 from MTR addressDetails.country');
            $table->string('contact_type', 10)->default('LEAD')->comment('LEAD or CLIENT — upgrade-only, never downgrade');
            $table->string('lead_status', 50)->nullable()->comment('From MTR leadDetails.status e.g. HOT LEAD');
            $table->string('lead_source', 100)->nullable();
            $table->string('affiliate', 100)->nullable()->comment('Referring IB partner');
            $table->string('branch', 100)->nullable()->comment('MTR branch name, denormalised');
            $table->string('account_manager', 100)->nullable();
            $table->timestampTz('became_active_client_at')->nullable()->comment('From MTR becomeActiveClientTime');
            $table->timestampTz('last_online_at')->nullable();
            $table->boolean('notes_contacted')->default(false)->comment('Manual flag — has been contacted');
            $table->uuid('duplicate_of_person_id')->nullable()->index();
            $table->timestampTz('mtr_last_synced_at')->nullable();
            $table->timestampsTz();

            $table->index('contact_type');
            $table->index('branch');
            $table->index('lead_source');
            $table->index('account_manager');
            $table->index('became_active_client_at');
            $table->index('last_online_at');
        });

        // Self-referential FK added after table + PK exist
        Schema::table('people', function (Blueprint $table) {
            $table->foreign('duplicate_of_person_id')
                ->references('id')
                ->on('people')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of_person_id']);
        });
        Schema::dropIfExists('people');
    }
};
