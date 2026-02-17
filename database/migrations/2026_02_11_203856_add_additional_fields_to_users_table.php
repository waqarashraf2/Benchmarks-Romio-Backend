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
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['ceo', 'director', 'operations_manager', 'drawer', 'checker', 'qa', 'designer', 'accounts_manager'])->after('password');
            $table->string('country', 50)->nullable()->after('role');
            $table->string('department', 50)->nullable()->after('country');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete()->after('department');
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete()->after('project_id');
            $table->enum('layer', ['drawer', 'checker', 'qa', 'designer'])->nullable()->after('team_id');
            $table->boolean('is_active')->default(true)->after('layer');
            $table->timestamp('last_activity')->nullable()->after('is_active');
            $table->integer('inactive_days')->default(0)->after('last_activity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['team_id']);
            $table->dropColumn([
                'role', 'country', 'department', 'project_id', 'team_id',
                'layer', 'is_active', 'last_activity', 'inactive_days'
            ]);
        });
    }
};
