<?php

namespace Database\Seeders;

use App\Enums\AccessLevel;
use App\Enums\BrandRole;
use App\Enums\ClientStatus;
use App\Enums\PortalSection;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development accounts and one demo brand.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Safe to re-run: everything is updateOrCreate'd by a natural key.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        // --- Breakfast side -------------------------------------------------
        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin Breakfast',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'role' => UserRole::Admin,
            ]
        );

        // --- A brand to work with ------------------------------------------
        $client = Client::updateOrCreate(
            ['slug' => 'the-coffee-club'],
            [
                'name' => 'The Coffee Club',
                'industry' => 'Café de especialidad',
                'status' => ClientStatus::Activo,
                'contact_name' => 'María García',
                'contact_email' => 'maria@thecoffeeclub.com',
                'notes' => "Marca de café con tres locales.\nQuieren pasar de cafetería a marca con criterio propio.",
                'onboarded_at' => now()->subMonths(5),
            ]
        );

        /*
         * The brand owner, granted by Breakfast — the normal shape: she sees
         * all of her brand. Read on every grantable section, because Read is
         * the only level a grantable section has: the client portal is
         * read-only throughout. See User::grantCeiling().
         *
         * Equipo is not in here: User::accessTo() gives it by role, because
         * that is what being the owner means.
         */
        $ownerPermissions = [];

        foreach (PortalSection::grantable() as $section) {
            $ownerPermissions[$section->value] = AccessLevel::Read->value;
        }

        $clientUser = User::updateOrCreate(
            ['email' => 'client@example.com'],
            [
                'name' => 'María García',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'role' => UserRole::ClienteOwner,
            ]
        );

        // ⚠️ THE MEMBERSHIP IS THE BRAND, and this seeder did not write one.
        // It set users.client_id and users.permissions, which the portal
        // stopped reading on 2026-09-14 and which were dropped on 2026-09-15 —
        // so the demo accounts it made had no brand at all and /portal closed
        // on them. See docs/multimarca.md.
        $clientUser->brands()->syncWithoutDetaching([
            $client->id => [
                'role' => BrandRole::Owner->value,
                'permissions' => json_encode((object) $ownerPermissions),
            ],
        ]);

        /*
         * A teammate, granted by María rather than by Breakfast, and strictly
         * within what she holds: two of her four sections, nothing else.
         * Exercising the "same or less" rule — and the absence of the other
         * two is the whole representation of no access, since there is no
         * "none" level.
         */
        $member = User::updateOrCreate(
            ['email' => 'member@example.com'],
            [
                'name' => 'Diego Ruiz',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'role' => UserRole::ClienteMiembro,
            ]
        );

        $member->brands()->syncWithoutDetaching([
            $client->id => [
                'role' => BrandRole::Miembro->value,
                'permissions' => json_encode((object) [
                    PortalSection::Estrategia->value => AccessLevel::Read->value,
                    PortalSection::Reuniones->value => AccessLevel::Read->value,
                ]),
            ],
        ]);

        $this->command->newLine();
        $this->command->info('Cuentas de desarrollo listas (contraseña: '.self::PASSWORD.')');
        $this->command->table(
            ['Correo', 'Rol', 'Marca', 'Entra a', 'Alcance'],
            [
                [$admin->email, $admin->role->label(), '—', '/admin', 'Todo'],
                [$clientUser->email, $clientUser->role->label(), $client->name, '/portal', 've las 4 secciones'],
                [$member->email, $member->role->label(), $client->name, '/portal', 've 2 de 4'],
            ]
        );
    }
}
