<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds per-branch persona + outreach controls.
 *
 *   persona_name        — first name the AI signs off as for this branch
 *                         (e.g. "Alex"). Each branch is its own consumer-
 *                         facing brand and gets its own persona.
 *   persona_signoff     — optional literal override of the full signoff
 *                         string. When null, callers build it as
 *                         "{persona_name} from {customer_facing_name}".
 *   customer_facing_name — what the AI calls this branch in messages.
 *                          Defaults to `name` when null. Use to scrub
 *                          internal labels ("NO Withdrawal Branch").
 *   outreach_enabled    — false → no draft may be generated for a person
 *                         on this branch. Test/internal branches sit at
 *                         false by policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('persona_name', 60)->nullable()->after('name');
            $table->string('persona_signoff', 200)->nullable()->after('persona_name');
            $table->string('customer_facing_name', 120)->nullable()->after('persona_signoff');
            $table->boolean('outreach_enabled')->default(false)->after('customer_facing_name');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn(['persona_name', 'persona_signoff', 'customer_facing_name', 'outreach_enabled']);
        });
    }
};
