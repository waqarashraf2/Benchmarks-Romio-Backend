<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Critical indexes for daily operations dashboard performance.
     */
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            // Composite index for daily operations query
            $table->index(['project_id', 'status', 'completed_at'], 'idx_work_items_daily_ops');
            $table->index(['assigned_user_id', 'completed_at'], 'idx_work_items_user_completed');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Composite indexes for delivered orders
            $table->index(['project_id', 'workflow_state', 'delivered_at'], 'idx_orders_delivered');
            // For received counts
            $table->index(['project_id', 'received_at'], 'idx_orders_received');
            // For pending counts
            $table->index(['project_id', 'workflow_state'], 'idx_orders_state');
        });

        Schema::table('order_checklists', function (Blueprint $table) {
            // For QA checklist queries
            $table->index(['order_id', 'is_checked'], 'idx_checklists_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropIndex('idx_work_items_daily_ops');
            $table->dropIndex('idx_work_items_user_completed');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_delivered');
            $table->dropIndex('idx_orders_received');
            $table->dropIndex('idx_orders_state');
        });

        Schema::table('order_checklists', function (Blueprint $table) {
            $table->dropIndex('idx_checklists_order');
        });
    }
};
