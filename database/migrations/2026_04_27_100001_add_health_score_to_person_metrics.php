<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('person_metrics', function (Blueprint $table) {
            $table->integer('health_score')->nullable()->after('refreshed_at');
            $table->string('health_grade', 1)->nullable()->after('health_score'); // A/B/C/D/F
            $table->jsonb('health_score_breakdown')->nullable()->after('health_grade'); // per-factor detail
            $table->timestampTz('health_score_calculated_at')->nullable()->after('health_score_breakdown');
        });

        DB::statement('CREATE INDEX person_metrics_health_score_idx ON person_metrics (health_score)');
    }

    public function down(): void
    {
        Schema::table('person_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'health_score',
                'health_grade',
                'health_score_breakdown',
                'health_score_calculated_at',
            ]);
        });
    }
};
