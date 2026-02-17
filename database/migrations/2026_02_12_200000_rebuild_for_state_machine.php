<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Work Items (per-stage records) ──
        Schema::create('work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 30); // DRAW, CHECK, QA, DESIGN
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('pending'); // pending, in_progress, completed, rejected
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('comments')->nullable();
            $table->json('flags')->nullable();
            $table->string('rework_reason')->nullable();
            $table->string('rejection_code')->nullable();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->timestamps();

            $table->index(['order_id', 'stage']);
            $table->index(['assigned_user_id', 'status']);
            $table->index(['project_id', 'stage', 'status']);
        });

        // ── Month Locks ──
        Schema::create('month_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('month');
            $table->unsignedSmallInteger('year');
            $table->boolean('is_locked')->default(false);
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unlocked_at')->nullable();
            $table->json('frozen_counts')->nullable(); // snapshot of production counts at lock time
            $table->timestamps();

            $table->unique(['project_id', 'month', 'year']);
        });

        // ── Audit Logs rebuild (add missing fields) ──
        Schema::table('activity_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('activity_logs', 'entity_type')) {
                $table->string('entity_type', 50)->nullable()->after('action');
            }
            if (!Schema::hasColumn('activity_logs', 'entity_id')) {
                $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            }
            if (!Schema::hasColumn('activity_logs', 'project_id')) {
                $table->foreignId('project_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            }
        });

        // ── Orders: add state machine fields ──
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'workflow_state')) {
                $table->string('workflow_state', 30)->default('RECEIVED')->after('status');
            }
            if (!Schema::hasColumn('orders', 'workflow_type')) {
                $table->string('workflow_type', 20)->default('FP_3_LAYER')->after('workflow_state');
            }
            if (!Schema::hasColumn('orders', 'due_date')) {
                $table->date('due_date')->nullable()->after('received_at');
            }
            if (!Schema::hasColumn('orders', 'attempt_draw')) {
                $table->unsignedInteger('attempt_draw')->default(0)->after('recheck_count');
            }
            if (!Schema::hasColumn('orders', 'attempt_check')) {
                $table->unsignedInteger('attempt_check')->default(0)->after('attempt_draw');
            }
            if (!Schema::hasColumn('orders', 'attempt_qa')) {
                $table->unsignedInteger('attempt_qa')->default(0)->after('attempt_check');
            }
            if (!Schema::hasColumn('orders', 'is_on_hold')) {
                $table->boolean('is_on_hold')->default(false)->after('attempt_qa');
            }
            if (!Schema::hasColumn('orders', 'hold_reason')) {
                $table->string('hold_reason')->nullable()->after('is_on_hold');
            }
            if (!Schema::hasColumn('orders', 'hold_set_by')) {
                $table->foreignId('hold_set_by')->nullable()->after('hold_reason')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('completed_at');
            }
            $table->index('workflow_state');
            $table->index(['project_id', 'workflow_state']);
        });

        // ── Projects: add workflow config ──
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'workflow_type')) {
                $table->string('workflow_type', 20)->default('FP_3_LAYER')->after('department');
            }
            if (!Schema::hasColumn('projects', 'sla_config')) {
                $table->json('sla_config')->nullable()->after('workflow_type');
            }
            if (!Schema::hasColumn('projects', 'invoice_categories_config')) {
                $table->json('invoice_categories_config')->nullable()->after('sla_config');
            }
            if (!Schema::hasColumn('projects', 'client_portal_config')) {
                $table->json('client_portal_config')->nullable()->after('invoice_categories_config');
            }
            if (!Schema::hasColumn('projects', 'target_config')) {
                $table->json('target_config')->nullable()->after('client_portal_config');
            }
            if (!Schema::hasColumn('projects', 'wip_cap')) {
                $table->unsignedInteger('wip_cap')->default(1)->after('target_config');
            }
        });

        // ── Teams: add structure config ──
        Schema::table('teams', function (Blueprint $table) {
            if (!Schema::hasColumn('teams', 'structure_config')) {
                $table->json('structure_config')->nullable()->after('designer_count');
            }
            if (!Schema::hasColumn('teams', 'auto_assignment_rules')) {
                $table->json('auto_assignment_rules')->nullable()->after('structure_config');
            }
        });

        // ── Users: add session + assignment fields ──
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'current_session_token')) {
                $table->string('current_session_token', 64)->nullable()->after('remember_token');
            }
            if (!Schema::hasColumn('users', 'wip_count')) {
                $table->unsignedInteger('wip_count')->default(0)->after('inactive_days');
            }
            if (!Schema::hasColumn('users', 'today_completed')) {
                $table->unsignedInteger('today_completed')->default(0)->after('wip_count');
            }
            if (!Schema::hasColumn('users', 'shift_start')) {
                $table->time('shift_start')->nullable()->after('today_completed');
            }
            if (!Schema::hasColumn('users', 'shift_end')) {
                $table->time('shift_end')->nullable()->after('shift_start');
            }
            if (!Schema::hasColumn('users', 'is_absent')) {
                $table->boolean('is_absent')->default(false)->after('shift_end');
            }
            if (!Schema::hasColumn('users', 'daily_target')) {
                $table->unsignedInteger('daily_target')->default(0)->after('is_absent');
            }
        });

        // ── Invoices: add full workflow states ──
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'issued_by')) {
                $table->foreignId('issued_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'issued_at')) {
                $table->timestamp('issued_at')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('invoices', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('issued_at');
            }
            if (!Schema::hasColumn('invoices', 'locked_month_id')) {
                $table->foreignId('locked_month_id')->nullable()->after('sent_at')->constrained('month_locks')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_items');
        Schema::dropIfExists('month_locks');

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn(['entity_type', 'entity_id', 'project_id']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['workflow_state', 'workflow_type', 'due_date', 'attempt_draw', 'attempt_check', 'attempt_qa', 'is_on_hold', 'hold_reason', 'hold_set_by', 'delivered_at']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['workflow_type', 'sla_config', 'invoice_categories_config', 'client_portal_config', 'target_config', 'wip_cap']);
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['structure_config', 'auto_assignment_rules']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['current_session_token', 'wip_count', 'today_completed', 'shift_start', 'shift_end', 'is_absent', 'daily_target']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['issued_by', 'issued_at', 'sent_at', 'locked_month_id']);
        });
    }
};
