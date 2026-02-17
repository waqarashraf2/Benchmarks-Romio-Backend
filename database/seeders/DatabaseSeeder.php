<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            ProjectSeeder::class,
            TeamSeeder::class,
            UserSeeder::class,
            OrderSeeder::class,
            ChecklistTemplateSeeder::class,
        ]);
    }
}
