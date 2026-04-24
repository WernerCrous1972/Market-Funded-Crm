<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            // MTR addressDetails.country returns full names (e.g. "South Africa") in addition
            // to ISO-2 codes, so varchar(3) is too narrow.
            $table->string('country', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('country', 3)->nullable()->change();
        });
    }
};
