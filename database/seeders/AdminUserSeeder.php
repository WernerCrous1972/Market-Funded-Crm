<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'werner@market-funded.com'],
            [
                'name'     => 'Werner Crous',
                'password' => Hash::make('changeme123!'),
                'role'     => 'ADMIN',
            ]
        );

        $this->command->info('Admin user: werner@market-funded.com / changeme123!');
        $this->command->warn('Change the password after first login.');
    }
}
