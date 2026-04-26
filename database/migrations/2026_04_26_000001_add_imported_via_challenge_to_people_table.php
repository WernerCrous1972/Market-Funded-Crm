<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->boolean('imported_via_challenge')
                ->default(false)
                ->after('mtr_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn('imported_via_challenge');
        });
    }
};
