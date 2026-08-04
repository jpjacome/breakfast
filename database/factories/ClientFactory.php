<?php

namespace Database\Factories;

use App\Enums\ClientStatus;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Client::uniqueSlug($name),
            'industry' => fake()->randomElement([
                'Café', 'Moda', 'Belleza', 'Gastronomía', 'Salud', 'Educación',
            ]),
            'status' => ClientStatus::Activo,
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'onboarded_at' => now()->subDays(fake()->numberBetween(1, 180)),
        ];
    }
}
