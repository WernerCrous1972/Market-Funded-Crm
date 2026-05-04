<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('mtr_account_uuid', 64)
                ->nullable()
                ->unique()
                ->after('mtr_updated_at')
                ->comment('MTR CRM account UUID — used to query timeline events (login timestamps etc.)');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('mtr_account_uuid');
        });
    }
};
