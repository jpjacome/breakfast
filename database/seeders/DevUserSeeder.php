<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Throwaway seeder used to verify the auth flow end to end.
 *
 * This is NOT the real user model — clients, roles and invitations come in
 * the next phase. Delete or replace this once ClientSeeder exists.
 *
 *   php artisan db:seed --class=DevUserSeeder
 */
class DevUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'maria@lamarca.com'],
            [
                'name' => 'María García',
                'password' => Hash::make('breakfast2026'),
                'email_verified_at' => now(),
            ]
        );

        $this->command->info("Usuario de prueba listo: {$user->email} (id {$user->id})");
    }
}
