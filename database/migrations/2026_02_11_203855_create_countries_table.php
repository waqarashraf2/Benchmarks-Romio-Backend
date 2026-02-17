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
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        
        // Seed default countries
        DB::table('countries')->insert([
            ['code' => 'UK', 'name' => 'United Kingdom', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'AU', 'name' => 'Australia', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'CA', 'name' => 'Canada', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'US', 'name' => 'USA', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
