<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the two Breakfast admin accounts.
 *
 *   php artisan db:seed --class=BreakfastAdminSeeder
 */
class BreakfastAdminSeeder extends Seeder
{
    public function run(): void
    {
        $admins = [
            ['email' => 'pablo@vamosdebreakfast.com', 'name' => 'Pablo'],
            ['email' => 'anamaria@vamosdebreakfast.com', 'name' => 'Ana María'],
        ];

        foreach ($admins as $admin) {
            $user = User::updateOrCreate(
                ['email' => $admin['email']],
                [
                    'name' => $admin['name'],
                    'password' => Hash::make('Breakfast2020.'),
                    'role' => UserRole::Admin,
                    'email_verified_at' => now(),
                ]
            );

            $this->command->info("Admin listo: {$user->email} (id {$user->id})");
        }
    }
}
