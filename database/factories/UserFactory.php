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
 *
 * ⚠️ A BRAND MEMBERSHIP IS DECLARED, NEVER INFERRED. Until 2026-09-15 this
 * factory wrote users.client_id and users.permissions and then copied them onto
 * the brand_user pivot after creating — the single-brand shorthand kept alive so
 * the suite did not have to be rewritten in the same pass that changed the
 * storage (see the brand_user migration). Those columns are gone now, so the
 * membership is said out loud: clientOwner($brand) and clientMember($brand).
 *
 * ⚠️ AND A BARE User::factory()->create() IS A CLIENT USER IN NO BRAND, which
 * is a broken record on purpose — accessTo() fails closed on it rather than
 * guessing. It used to get an invisible brand of its own, which meant a test
 * could depend on a membership nothing in it mentioned. A test that needs a
 * brand now says which.
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
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Breakfast staff belong to no brand — they reach them by role or by assignment. */
    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    public function equipo(): static
    {
        return $this->state(fn () => ['role' => UserRole::Equipo]);
    }

    /**
     * Owner of a brand — a new one when none is named.
     *
     * Takes a Client or its id: call sites reach for both, and refusing one of
     * them buys nothing but a type error in a test.
     *
     * @param  array<string, string>  $permissions  section value => access level
     */
    public function clientOwner(Client|int|null $client = null, array $permissions = []): static
    {
        return $this->state(fn () => ['role' => UserRole::ClienteOwner])
            ->inBrand($client, BrandRole::Owner, $permissions);
    }

    /**
     * Member of a brand, with whatever sections they were granted there.
     *
     * @param  array<string, string>  $permissions  section value => access level
     */
    public function clientMember(Client|int|null $client = null, array $permissions = []): static
    {
        return $this->state(fn () => ['role' => UserRole::ClienteMiembro])
            ->inBrand($client, BrandRole::Miembro, $permissions);
    }

    /**
     * One membership row, written after the user exists.
     *
     * ⚠️ syncWithoutDetaching, NOT sync: calling clientOwner() and then
     * clientMember() for a second brand is how a multi-brand account is built
     * in a test, and sync would silently drop the first.
     *
     * ⚠️ THE MAP IS ENCODED HERE. brand_user.permissions is a json column but a
     * pivot is not a model, so nothing casts it on the way in or out — an array
     * passed straight through arrives as the string "Array". Same trap
     * CLAUDE.md §13 item 18 names from the reading side.
     *
     * @param  array<string, string>  $permissions
     */
    private function inBrand(Client|int|null $client, BrandRole $role, array $permissions): static
    {
        return $this->afterCreating(function (User $user) use ($client, $role, $permissions): void {
            $brandId = match (true) {
                $client instanceof Client => $client->id,
                is_int($client) => $client,
                default => Client::factory()->create()->id,
            };

            $user->brands()->syncWithoutDetaching([
                $brandId => [
                    'role' => $role->value,
                    'permissions' => json_encode((object) $permissions),
                ],
            ]);
        });
    }
}
