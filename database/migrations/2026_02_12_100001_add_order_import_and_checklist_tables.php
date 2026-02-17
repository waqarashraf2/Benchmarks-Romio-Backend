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
        // Order Import Sources - tracks how orders are imported per project
        Schema::create('order_import_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['api', 'cron', 'csv', 'manual'])->default('manual');
            $table->string('name');
            $table->string('api_endpoint')->nullable();
            $table->json('api_credentials')->nullable(); // encrypted
            $table->string('cron_schedule')->nullable(); // e.g., "0 */2 * * *"
            $table->timestamp('last_sync_at')->nullable();
            $table->integer('orders_synced')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('field_mapping')->nullable(); // maps external fields to our fields
            $table->timestamps();
        });

        // Order Import Logs - tracks each import batch
        Schema::create('order_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_source_id')->constrained('order_import_sources')->cascadeOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->integer('total_rows')->default(0);
            $table->integer('imported_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->string('file_path')->nullable(); // for CSV imports
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // Checklist Templates - per project checklist items
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->enum('layer', ['drawer', 'checker', 'qa', 'designer']);
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['project_id', 'layer']);
        });

        // Order Checklists - completed checklist items per order
        Schema::create('order_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('completed_by')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_checked')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->unique(['order_id', 'checklist_template_id', 'completed_by'], 'order_checklist_unique');
        });

        // Add rejection/recheck fields to orders
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('import_source', ['api', 'cron', 'csv', 'manual'])->default('manual')->after('metadata');
            $table->foreignId('import_log_id')->nullable()->after('import_source')->constrained('order_import_logs')->nullOnDelete();
            $table->integer('recheck_count')->default(0)->after('import_log_id');
            $table->foreignId('rejected_by')->nullable()->after('recheck_count')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->enum('rejection_type', ['quality', 'incomplete', 'incorrect', 'other'])->nullable()->after('rejection_reason');
            $table->boolean('checker_self_corrected')->default(false)->after('rejection_type');
            $table->string('client_portal_id')->nullable()->after('checker_self_corrected'); // ID in client's system
            $table->timestamp('client_portal_synced_at')->nullable()->after('client_portal_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['import_log_id']);
            $table->dropForeign(['rejected_by']);
            $table->dropColumn([
                'import_source',
                'import_log_id',
                'recheck_count',
                'rejected_by',
                'rejected_at',
                'rejection_reason',
                'rejection_type',
                'checker_self_corrected',
                'client_portal_id',
                'client_portal_synced_at',
            ]);
        });

        Schema::dropIfExists('order_checklists');
        Schema::dropIfExists('checklist_templates');
        Schema::dropIfExists('order_import_logs');
        Schema::dropIfExists('order_import_sources');
    }
};
