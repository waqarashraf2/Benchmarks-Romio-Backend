<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Help requests table - for workers to request clarification
        Schema::create('help_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->text('question');
            $table->text('response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('responded_at')->nullable();
            $table->enum('status', ['pending', 'answered', 'closed'])->default('pending');
            $table->timestamps();
        });

        // Issue flags table - for workers to flag problems
        Schema::create('issue_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('flagged_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('flag_type'); // quality, missing_info, wrong_specs, other
            $table->text('description');
            $table->enum('severity', ['low', 'medium', 'high'])->default('medium');
            $table->enum('status', ['open', 'acknowledged', 'resolved'])->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
        });

        // Add supervisor notes to orders
        Schema::table('orders', function (Blueprint $table) {
            $table->text('supervisor_notes')->nullable()->after('metadata');
            $table->json('attachments')->nullable()->after('supervisor_notes');
        });

        // Add time tracking to work_items
        Schema::table('work_items', function (Blueprint $table) {
            $table->integer('time_spent_seconds')->default(0)->after('completed_at');
            $table->timestamp('last_timer_start')->nullable()->after('time_spent_seconds');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_requests');
        Schema::dropIfExists('issue_flags');
        
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['supervisor_notes', 'attachments']);
        });
        
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn(['time_spent_seconds', 'last_timer_start']);
        });
    }
};
