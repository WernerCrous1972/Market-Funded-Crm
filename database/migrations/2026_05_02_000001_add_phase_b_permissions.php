<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase B — Permission system foundation.
 *
 * Single atomic migration:
 *   1. Add 14 boolean permission columns to `users` (all NOT NULL DEFAULT false)
 *   2. Create `user_branch_access` pivot (many-to-many users ↔ branches)
 *   3. Create `permission_audit_logs` (immutable — no updated_at)
 *   4. Create `permission_templates` (7 starter templates seeded inline)
 *   5. Bootstrap Werner: is_super_admin = true + all included-branch pivot rows + audit log entry
 *   6. Seed 7 starter templates
 */
return new class extends Migration
{
    private const PERMISSION_COLUMNS = [
        'is_super_admin',
        'assigned_only',
        'can_view_client_financials',
        'can_view_branch_financials',
        'can_view_health_scores',
        'can_make_notes',
        'can_send_whatsapp',
        'can_send_email',
        'can_create_email_campaigns',
        'can_edit_clients',
        'can_assign_clients',
        'can_create_tasks',
        'can_assign_tasks_to_others',
        'can_export',
    ];

    public function up(): void
    {
        // ── 1. Add 14 permission columns to users ────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('role')
                ->comment('Gate::before bypass. Only super admins can grant. Hidden from template UI.');
            $table->boolean('assigned_only')->default(false)->after('is_super_admin')
                ->comment('true = only sees clients where they are account_manager.');
            $table->boolean('can_view_client_financials')->default(false)->after('assigned_only')
                ->comment('Per-client deposit/withdrawal history on person detail page.');
            $table->boolean('can_view_branch_financials')->default(false)->after('can_view_client_financials')
                ->comment('Aggregated branch dashboards, revenue widgets, exportable financial reports.');
            $table->boolean('can_view_health_scores')->default(false)->after('can_view_branch_financials')
                ->comment('Health scores on client records and At-Risk Clients widget.');
            $table->boolean('can_make_notes')->default(false)->after('can_view_health_scores')
                ->comment('Create notes on clients. Edit/delete is ADMIN-only by hardcoded rule.');
            $table->boolean('can_send_whatsapp')->default(false)->after('can_make_notes')
                ->comment('Send WhatsApp messages via CRM (subject to WA_FEATURE_ENABLED).');
            $table->boolean('can_send_email')->default(false)->after('can_send_whatsapp')
                ->comment('Send individual emails via CRM.');
            $table->boolean('can_create_email_campaigns')->default(false)->after('can_send_email')
                ->comment('Create and schedule bulk email campaigns.');
            $table->boolean('can_edit_clients')->default(false)->after('can_create_email_campaigns')
                ->comment('Edit client record fields (full edit scope).');
            $table->boolean('can_assign_clients')->default(false)->after('can_edit_clients')
                ->comment('Reassign account_manager. Implicitly grants edit on lead_status + account_manager only.');
            $table->boolean('can_create_tasks')->default(false)->after('can_assign_clients')
                ->comment('Create tasks (for self by default).');
            $table->boolean('can_assign_tasks_to_others')->default(false)->after('can_create_tasks')
                ->comment('Assign tasks to other users. Requires can_create_tasks to be meaningful.');
            $table->boolean('can_export')->default(false)->after('can_assign_tasks_to_others')
                ->comment('Bulk export client lists to CSV.');
        });

        // ── 2. Create user_branch_access pivot ───────────────────────────────
        Schema::create('user_branch_access', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('branch_id');
            $table->timestampTz('granted_at');
            $table->uuid('granted_by')->nullable()
                ->comment('Null = system-initiated (e.g. bootstrap migration).');

            $table->primary(['user_id', 'branch_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('granted_by')->references('id')->on('users')->nullOnDelete();
        });

        // ── 3. Create permission_audit_logs ──────────────────────────────────
        // Immutable — no updated_at. Index on (target_user_id, created_at) for permission history view.
        Schema::create('permission_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('target_user_id');
            $table->uuid('actor_user_id')->nullable()
                ->comment('Null = system-initiated (bootstrap, automated rules).');
            $table->string('change_type', 50)
                ->comment('TOGGLE_CHANGED | BRANCH_GRANTED | BRANCH_REVOKED | TEMPLATE_APPLIED | SUPER_ADMIN_GRANTED | SUPER_ADMIN_REVOKED');
            $table->jsonb('changes')
                ->comment('{"field": "can_export", "from": false, "to": true} or {"template_name": "Admin"} etc.');
            $table->timestampTz('created_at');

            $table->index(['target_user_id', 'created_at']);
            $table->foreign('target_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // ── 4. Create permission_templates ───────────────────────────────────
        // branch_access_default is a UI hint ('ALL' | 'ONE' | 'CONFIGURABLE') — not enforced by DB.
        Schema::create('permission_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->text('description');
            $table->integer('display_order');
            $table->string('branch_access_default', 20)->default('ONE')
                ->comment('ALL | ONE | CONFIGURABLE — UI hint for branch pre-selection at user creation.');
            $table->jsonb('toggles')
                ->comment('14 boolean keys matching users permission columns. Stamped at user creation — not linked after.');
            $table->timestampsTz();
        });

        // ── 5. Bootstrap Werner ───────────────────────────────────────────────
        $werner = DB::table('users')->where('email', config('app.admin_email'))->first();
        $now    = now();

        if ($werner) {
            DB::table('users')
                ->where('id', $werner->id)
                ->update(['is_super_admin' => true]);

            // Insert pivot rows for all included branches
            $branches = DB::table('branches')->where('is_included', true)->get();
            foreach ($branches as $branch) {
                DB::table('user_branch_access')->insertOrIgnore([
                    'user_id'    => $werner->id,
                    'branch_id'  => $branch->id,
                    'granted_at' => $now,
                    'granted_by' => null,
                ]);
            }

            // Bootstrap audit log entry (no actor — system-initiated)
            DB::table('permission_audit_logs')->insert([
                'id'             => Str::uuid()->toString(),
                'target_user_id' => $werner->id,
                'actor_user_id'  => null,
                'change_type'    => 'SUPER_ADMIN_GRANTED',
                'changes'        => json_encode([
                    'field' => 'is_super_admin',
                    'from'  => false,
                    'to'    => true,
                    'note'  => 'Bootstrap — Phase B migration',
                ]),
                'created_at'     => $now,
            ]);
        }

        // ── 6. Seed 7 starter templates ──────────────────────────────────────
        $templates = [
            [
                'name'                  => 'Super Admin',
                'description'           => 'Full system access. Bypasses all permission checks. Only Werner grants this.',
                'display_order'         => 1,
                'branch_access_default' => 'ALL',
                'toggles'               => [
                    'is_super_admin'             => true,
                    'assigned_only'              => false,
                    'can_view_client_financials' => true,
                    'can_view_branch_financials' => true,
                    'can_view_health_scores'     => true,
                    'can_make_notes'             => true,
                    'can_send_whatsapp'          => true,
                    'can_send_email'             => true,
                    'can_create_email_campaigns' => true,
                    'can_edit_clients'           => true,
                    'can_assign_clients'         => true,
                    'can_create_tasks'           => true,
                    'can_assign_tasks_to_others' => true,
                    'can_export'                 => true,
                ],
            ],
            [
                'name'                  => 'Admin',
                'description'           => 'Read everywhere, light edit. No super admin, no exports.',
                'display_order'         => 2,
                'branch_access_default' => 'ALL',
                'toggles'               => [
                    'is_super_admin'             => false,
                    'assigned_only'              => false,
                    'can_view_client_financials' => true,
                    'can_view_branch_financials' => true,
                    'can_view_health_scores'     => true,
                    'can_make_notes'             => true,
                    'can_send_whatsapp'          => true,
                    'can_send_email'             => true,
                    'can_create_email_campaigns' => false,
                    'can_edit_clients'           => false,
                    'can_assign_clients'         => false,
                    'can_create_tasks'           => true,
                    'can_assign_tasks_to_others' => true,
                    'can_export'                 => false,
                ],
            ],
            [
                'name'                  => 'Broker Partner',
                'description'           => 'Single-branch partner. Full visibility, can assign clients and tasks, no full edit.',
                'display_order'         => 3,
                'branch_access_default' => 'ONE',
                'toggles'               => [
                    'is_super_admin'             => false,
                    'assigned_only'              => false,
                    'can_view_client_financials' => true,
                    'can_view_branch_financials' => true,
                    'can_view_health_scores'     => true,
                    'can_make_notes'             => true,
                    'can_send_whatsapp'          => true,
                    'can_send_email'             => true,
                    'can_create_email_campaigns' => false,
                    'can_edit_clients'           => false,
                    'can_assign_clients'         => true,
                    'can_create_tasks'           => true,
                    'can_assign_tasks_to_others' => true,
                    'can_export'                 => false,
                ],
            ],
            [
                'name'                  => 'Master IB / IB / Sales Manager',
                'description'           => 'Branch manager. Sees all clients in branch, can communicate, cannot reassign or edit.',
                'display_order'         => 4,
                'branch_access_default' => 'ONE',
                'toggles'               => [
                    'is_super_admin'             => false,
                    'assigned_only'              => false,
                    'can_view_client_financials' => true,
                    'can_view_branch_financials' => true,
                    'can_view_health_scores'     => true,
                    'can_make_notes'             => true,
                    'can_send_whatsapp'          => true,
                    'can_send_email'             => true,
                    'can_create_email_campaigns' => false,
                    'can_edit_clients'           => false,
                    'can_assign_clients'         => false,
                    'can_create_tasks'           => true,
                    'can_assign_tasks_to_others' => true,
                    'can_export'                 => false,
                ],
            ],
            [
                'name'                  => 'Sales Agent (assigned only)',
                'description'           => 'Sees only clients where they are the account manager. Standard sales role.',
                'display_order'         => 5,
                'branch_access_default' => 'ONE',
                'toggles'               => [
                    'is_super_admin'             => false,
                    'assigned_only'              => true,
                    'can_view_client_financials' => true,
                    'can_view_branch_financials' => false,
                    'can_view_health_scores'     => true,
                    'can_make_notes'             => true,
                    'can_send_whatsapp'          => true,
                    'can_send_email'             => true,
                    'can_create_email_campaigns' => false,
                    'can_edit_clients'           => false,
                    'can_assign_clients'         => false,
                    'can_create_tasks'           => true,
                    'can_assign_tasks_to_others' => false,
                    'can_export'                 => false,
                ],
            ],
            [
                'name'                  => 'Sales Agent (full branch view)',
                'description'           => 'Sees all clients in branch. Same actions as assigned-only agent.',
                'display_order'         => 6,
                'branch_access_default' => 'ONE',
                'toggles'               => [
                    'is_super_admin'             => false,
                    'assigned_only'              => false,
                    'can_view_client_financials' => true,
                    'can_view_branch_financials' => false,
                    'can_view_health_scores'     => true,
                    'can_make_notes'             => true,
                    'can_send_whatsapp'          => true,
                    'can_send_email'             => true,
                    'can_create_email_campaigns' => false,
                    'can_edit_clients'           => false,
                    'can_assign_clients'         => false,
                    'can_create_tasks'           => true,
                    'can_assign_tasks_to_others' => false,
                    'can_export'                 => false,
                ],
            ],
            [
                'name'                  => 'Viewer',
                'description'           => 'Read-only. Configurable branches. No send, no notes, no edit, no export.',
                'display_order'         => 7,
                'branch_access_default' => 'CONFIGURABLE',
                'toggles'               => [
                    'is_super_admin'             => false,
                    'assigned_only'              => false,
                    'can_view_client_financials' => true,
                    'can_view_branch_financials' => false,
                    'can_view_health_scores'     => true,
                    'can_make_notes'             => false,
                    'can_send_whatsapp'          => false,
                    'can_send_email'             => false,
                    'can_create_email_campaigns' => false,
                    'can_edit_clients'           => false,
                    'can_assign_clients'         => false,
                    'can_create_tasks'           => false,
                    'can_assign_tasks_to_others' => false,
                    'can_export'                 => false,
                ],
            ],
        ];

        foreach ($templates as $template) {
            DB::table('permission_templates')->insert([
                'id'                    => Str::uuid()->toString(),
                'name'                  => $template['name'],
                'description'           => $template['description'],
                'display_order'         => $template['display_order'],
                'branch_access_default' => $template['branch_access_default'],
                'toggles'               => json_encode($template['toggles']),
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_templates');
        Schema::dropIfExists('permission_audit_logs');
        Schema::dropIfExists('user_branch_access');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(self::PERMISSION_COLUMNS);
        });
    }
};
