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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('country', 50);
            $table->enum('department', ['floor_plan', 'photos_enhancement']);
            $table->string('client_name');
            $table->enum('status', ['active', 'inactive', 'completed'])->default('active');
            $table->integer('total_orders')->default(0);
            $table->integer('completed_orders')->default(0);
            $table->integer('pending_orders')->default(0);
            $table->integer('total_teams')->default(0);
            $table->integer('active_teams')->default(0);
            $table->integer('total_staff')->default(0);
            $table->integer('active_staff')->default(0);
            $table->json('workflow_layers');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['country', 'department']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
