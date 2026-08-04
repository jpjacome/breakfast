<?php

namespace Database\Seeders;

use App\Enums\ClientStatus;
use App\Enums\ContextDocumentKind;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
                'client_id' => null,
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

        // --- Client side ----------------------------------------------------
        $clientUser = User::updateOrCreate(
            ['email' => 'client@example.com'],
            [
                'name' => 'María García',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'role' => UserRole::ClienteOwner,
                'client_id' => $client->id,
            ]
        );

        $this->seedContextFromBrief($client, $admin);

        $this->command->newLine();
        $this->command->info('Cuentas de desarrollo listas (contraseña: '.self::PASSWORD.')');
        $this->command->table(
            ['Correo', 'Rol', 'Marca', 'Entra a'],
            [
                [$admin->email, $admin->role->label(), '—', '/admin'],
                [$clientUser->email, $clientUser->role->label(), $client->name, '/portal'],
            ]
        );
    }

    /**
     * If the real brief PDFs are sitting in brief/, load them as context so
     * the document list isn't empty on a fresh install. Skipped silently
     * when they aren't there (CI, a teammate's clone).
     */
    private function seedContextFromBrief(Client $client, User $uploader): void
    {
        // Match on a substring rather than the full filename: the creative
        // direction PDF's name contains a COMBINING acute accent (o + U+0301),
        // which is a different byte sequence from a precomposed "ó" and will
        // never match an exact string literal here.
        $rules = [
            'brief' => [
                'title' => 'Brief · The Brand Therapist',
                'kind' => ContextDocumentKind::Brief,
                'description' => 'Qué necesita el cliente del portal, en un tweet.',
            ],
            'creativa' => [
                'title' => 'Dirección creativa Breakfast',
                'kind' => ContextDocumentKind::Estrategia,
                'description' => "Personalidad, cromática, do's y don'ts.",
            ],
        ];

        foreach ((array) glob(base_path('brief/*.pdf')) as $source) {
            $filename = basename($source);
            $haystack = mb_strtolower($filename);

            $meta = null;
            foreach ($rules as $needle => $candidate) {
                if (str_contains($haystack, $needle)) {
                    $meta = $candidate;
                    break;
                }
            }

            if ($meta === null) {
                continue;
            }

            if ($client->contextDocuments()->where('title', $meta['title'])->exists()) {
                continue;
            }

            $path = "context/{$client->id}/".Str::random(40).'.pdf';
            Storage::disk('local')->put($path, file_get_contents($source));

            $client->contextDocuments()->create([
                'uploaded_by' => $uploader->id,
                'title' => $meta['title'],
                'description' => $meta['description'],
                'kind' => $meta['kind'],
                'disk' => 'local',
                'path' => $path,
                'original_name' => $filename,
                'mime' => 'application/pdf',
                'size_bytes' => filesize($source),
            ]);
        }
    }
}
