<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Local/dev convenience only — never run automatically (DatabaseSeeder does not
 * call this). Creates one account per role with a known password so a fresh
 * clone of the project has something to log in with:
 *
 *   docker compose exec app php artisan db:seed --class="Database\\Seeders\\DevAccountsSeeder"
 *
 * Do not run this against a shared or production database.
 */
class DevAccountsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $accounts = [
            ['email' => 'admin@stockroom.test', 'role' => 'admin', 'name' => 'Admin User', 'enrollment_number' => 'ADM-0001', 'programme' => 'Staff', 'semester' => '-'],
            ['email' => 'coordinator@stockroom.test', 'role' => 'coordinator', 'name' => 'Coordinator User', 'enrollment_number' => 'CRD-0001', 'programme' => 'Staff', 'semester' => '-'],
            ['email' => 'student@stockroom.test', 'role' => 'student', 'name' => 'Student User', 'enrollment_number' => 'STU-0001', 'programme' => 'B.Tech ECE', 'semester' => '5'],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                $account + ['password' => 'Password123!', 'contact' => '9000000000'],
            );
        }

        $this->command?->info('Dev accounts ready — password for all: Password123!');
    }
}
