<?php

namespace Database\Factories;

use App\Enums\BrandRole;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::ClienteMiembro,
            'client_id' => Client::factory(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Breakfast staff have no client. */
    public function admin(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Admin,
            'client_id' => null,
        ]);
    }

    public function equipo(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Equipo,
            'client_id' => null,
        ]);
    }

    public function clientOwner(?Client $client = null): static
    {
        return $this->state(fn () => [
            'role' => UserRole::ClienteOwner,
            'client_id' => $client?->id ?? Client::factory(),
        ]);
    }

    /**
     * Every client user made here lands in brand_user as well — ACC-01.
     *
     * client_id and role on the row are the factory's shorthand for "make this
     * person a member of that brand", which is what they meant when an account
     * had one brand. The pivot is what the app reads; this keeps the shorthand
     * working so the suite did not have to be rewritten in the same pass that
     * changed the storage. See the brand_user migration.
     *
     * Use ->brands([...]) or attach directly for the multi-brand cases.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->client_id === null || $user->role->isBreakfast()) {
                return;
            }

            $user->brands()->syncWithoutDetaching([
                $user->client_id => [
                    'role' => $user->role === UserRole::ClienteOwner
                        ? BrandRole::Owner->value
                        : BrandRole::Miembro->value,
                    'permissions' => $user->getRawOriginal('permissions'),
                ],
            ]);
        });
    }
}
