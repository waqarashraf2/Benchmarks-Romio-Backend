<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->enum('type', ['floor_plan', 'photos_enhancement']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        
        // Seed departments
        DB::table('departments')->insert([
            ['code' => 'FP', 'name' => 'Floor Plan', 'type' => 'floor_plan', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'PE', 'name' => 'Photos Enhancement', 'type' => 'photos_enhancement', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
