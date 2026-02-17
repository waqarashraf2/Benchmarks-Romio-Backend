<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * NOTE: Password is auto-hashed by User model's 'hashed' cast
     */
    public function run(): void
    {
        $users = [
            // CEO
            [
                'name' => 'John CEO',
                'email' => 'ceo@benchmark.com',
                'password' => 'password',
                'role' => 'ceo',
                'country' => 'Global',
                'department' => 'floor_plan',
                'is_active' => true,
                'last_activity' => now(),
            ],
            // Directors
            [
                'name' => 'Sarah Director',
                'email' => 'director@benchmark.com',
                'password' => 'password',
                'role' => 'director',
                'country' => 'UK',
                'department' => 'floor_plan',
                'is_active' => true,
                'last_activity' => now(),
            ],
            // Operations Managers
            [
                'name' => 'Mike Manager UK',
                'email' => 'manager.uk@benchmark.com',
                'password' => 'password',
                'role' => 'operations_manager',
                'country' => 'UK',
                'department' => 'floor_plan',
                'project_id' => 1,
                'is_active' => true,
                'last_activity' => now(),
            ],
            [
                'name' => 'Emma Manager AU',
                'email' => 'manager.au@benchmark.com',
                'password' => 'password',
                'role' => 'operations_manager',
                'country' => 'Australia',
                'department' => 'floor_plan',
                'project_id' => 3,
                'is_active' => true,
                'last_activity' => now(),
            ],
            // UK Floor Plan Team
            [
                'name' => 'Tom Drawer',
                'email' => 'drawer1@benchmark.com',
                'password' => 'password',
                'role' => 'drawer',
                'country' => 'UK',
                'department' => 'floor_plan',
                'project_id' => 1,
                'team_id' => 1,
                'layer' => 'drawer',
                'is_active' => true,
                'last_activity' => now(),
            ],
            [
                'name' => 'Lisa Checker',
                'email' => 'checker1@benchmark.com',
                'password' => 'password',
                'role' => 'checker',
                'country' => 'UK',
                'department' => 'floor_plan',
                'project_id' => 1,
                'team_id' => 1,
                'layer' => 'checker',
                'is_active' => true,
                'last_activity' => now(),
            ],
            [
                'name' => 'David QA',
                'email' => 'qa1@benchmark.com',
                'password' => 'password',
                'role' => 'qa',
                'country' => 'UK',
                'department' => 'floor_plan',
                'project_id' => 1,
                'team_id' => 1,
                'layer' => 'qa',
                'is_active' => true,
                'last_activity' => now(),
            ],
            // UK Photo Enhancement Team
            [
                'name' => 'Anna Designer',
                'email' => 'designer1@benchmark.com',
                'password' => 'password',
                'role' => 'designer',
                'country' => 'UK',
                'department' => 'photos_enhancement',
                'project_id' => 2,
                'team_id' => 2,
                'layer' => 'designer',
                'is_active' => true,
                'last_activity' => now(),
            ],
            [
                'name' => 'Bob QA Photos',
                'email' => 'qa.photos@benchmark.com',
                'password' => 'password',
                'role' => 'qa',
                'country' => 'UK',
                'department' => 'photos_enhancement',
                'project_id' => 2,
                'team_id' => 2,
                'layer' => 'qa',
                'is_active' => true,
                'last_activity' => now(),
            ],
            // Australia Team
            [
                'name' => 'Chris Drawer AU',
                'email' => 'drawer.au@benchmark.com',
                'password' => 'password',
                'role' => 'drawer',
                'country' => 'Australia',
                'department' => 'floor_plan',
                'project_id' => 3,
                'team_id' => 3,
                'layer' => 'drawer',
                'is_active' => true,
                'last_activity' => now(),
            ],
            // Accounts Manager
            [
                'name' => 'Jessica Accounts',
                'email' => 'accounts@benchmark.com',
                'password' => 'password',
                'role' => 'accounts_manager',
                'country' => 'UK',
                'department' => 'floor_plan',
                'is_active' => true,
                'last_activity' => now(),
            ],
        ];

        foreach ($users as $user) {
            User::create($user);
        }
    }
}
