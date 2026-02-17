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
    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->string('order_number')->unique();
        $table->foreignId('project_id')->constrained()->cascadeOnDelete();
        $table->string('client_reference')->nullable();
        $table->string('address')->nullable();
        $table->enum('current_layer', ['drawer', 'checker', 'qa', 'designer']);
        $table->enum('status', ['pending', 'in-progress', 'completed', 'on-hold'])->default('pending');
        $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
        $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
        $table->timestamp('received_at')->nullable();
        
        // ✅ New columns added to match your import service
        $table->integer('year')->nullable();
        $table->integer('month')->nullable();
        $table->string('date')->nullable();
        $table->string('client_name')->nullable();
        $table->timestamp('ausDatein')->nullable();
        $table->string('code')->nullable();
        $table->string('plan_type')->nullable();
        $table->string('instruction')->nullable();
        $table->string('project_type')->nullable();
        $table->string('due_in')->nullable();
        
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamps();
        
        $table->index(['project_id', 'status', 'current_layer']);
        $table->index('assigned_to');
        $table->index(['priority', 'received_at']);
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
