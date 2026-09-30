<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the demo accounts for each role plus the starter SOS queue.
     *
     * Passwords:
     *   admin@islasafe.test      -> TempAdmin123!   (temporary admin password)
     *   camp@islasafe.test       -> password
     *   resident@islasafe.test   -> password
     */
    public function run(): void
    {
        $accounts = [
            ['name' => 'MDRRMO Administrator', 'email' => 'admin@islasafe.test', 'role' => User::ROLE_ADMIN, 'password' => 'TempAdmin123!'],
            ['name' => 'Evacuation Camp Manager', 'email' => 'camp@islasafe.test', 'role' => User::ROLE_CAMP_MANAGER],
            ['name' => 'Resident Demo Account', 'email' => 'resident@islasafe.test', 'role' => User::ROLE_RESIDENT],
        ];

        foreach ($accounts as $account) {
            User::firstOrCreate(
                ['email' => $account['email']],
                [...$account, 'password' => Hash::make($account['password'] ?? 'password')],
            );
        }

        $this->call(SosRequestSeeder::class);
    }
}
