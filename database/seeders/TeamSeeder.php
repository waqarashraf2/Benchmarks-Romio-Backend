<?php

namespace Database\Seeders;

use App\Models\Team;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $teams = [
            [
                'project_id' => 1, // UK Floor Plan
                'name' => 'Team Alpha',
                'qa_count' => 1,
                'checker_count' => 1,
                'drawer_count' => 1,
                'designer_count' => 0,
                'is_active' => true,
            ],
            [
                'project_id' => 2, // UK Photo Enhancement
                'name' => 'Design Team A',
                'qa_count' => 1,
                'checker_count' => 0,
                'drawer_count' => 0,
                'designer_count' => 1,
                'is_active' => true,
            ],
            [
                'project_id' => 3, // Australia Floor Plan
                'name' => 'Sydney Team',
                'qa_count' => 1,
                'checker_count' => 1,
                'drawer_count' => 1,
                'designer_count' => 0,
                'is_active' => true,
            ],
            [
                'project_id' => 4, // Australia Photo Enhancement
                'name' => 'Melbourne Design Team',
                'qa_count' => 1,
                'checker_count' => 0,
                'drawer_count' => 0,
                'designer_count' => 1,
                'is_active' => true,
            ],
            [
                'project_id' => 5, // Canada Floor Plan
                'name' => 'Toronto Team',
                'qa_count' => 1,
                'checker_count' => 1,
                'drawer_count' => 1,
                'designer_count' => 0,
                'is_active' => true,
            ],
            [
                'project_id' => 6, // USA Floor Plan
                'name' => 'NYC Team',
                'qa_count' => 1,
                'checker_count' => 1,
                'drawer_count' => 1,
                'designer_count' => 0,
                'is_active' => true,
            ],
            [
                'project_id' => 7, // USA Photo Enhancement
                'name' => 'LA Design Team',
                'qa_count' => 1,
                'checker_count' => 0,
                'drawer_count' => 0,
                'designer_count' => 1,
                'is_active' => true,
            ],
        ];

        foreach ($teams as $team) {
            Team::create($team);
        }
    }
}
