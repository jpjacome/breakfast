<?php

use App\Enums\AccessLevel;
use App\Enums\BrandRole;
use App\Enums\PortalSection;
use App\Enums\UserRole;
use App\Models\AssistantMessage;
use App\Models\BrandAsset;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| ACC-01/02/03 — one account, many brands
|--------------------------------------------------------------------------
| Everything here is about the seam the single client_id used to hide: what
| is true of a PERSON versus what is true of a person IN A BRAND. Each test
| below is a place where getting that wrong would leak one brand into another.
*/

/** An account in two brands, with a different standing in each. */
function personInTwoBrands(
    array $first = ['estrategia' => 'read'],
    array $second = ['estrategia' => 'read'],
    BrandRole $firstRole = BrandRole::Owner,
    BrandRole $secondRole = BrandRole::Miembro,
): array {
    $alea = Client::factory()->create(['name' => 'Alea', 'slug' => 'alea']);
    $bruma = Client::factory()->create(['name' => 'Bruma', 'slug' => 'bruma']);

    // No membership from the factory: both are attached explicitly below, one
    // per brand, which is the whole point of this file.
    $user = User::factory()->create(['role' => UserRole::ClienteMiembro]);

    foreach ([[$alea, $firstRole, $first], [$bruma, $secondRole, $second]] as [$brand, $role, $map]) {
        $user->brands()->attach($brand->id, [
            'role' => $role->value,
            'permissions' => json_encode((object) $map),
        ]);
    }

    return [$user->fresh(), $alea, $bruma];
}

/* -------------------------------------------------------------------------
 | The account reaches both
 ------------------------------------------------------------------------- */

it('gives one account both of its brands', function () {
    [$user, $alea, $bruma] = personInTwoBrands();

    expect($user->brands->pluck('name')->sort()->values()->all())
        ->toBe(['Alea', 'Bruma']);
});

it('shows a picker only when there is more than one brand', function () {
    [$user] = personInTwoBrands();

    actingAs($user)->get(route('portal.home'))
        ->assertOk()
        ->assertSee('Cambiar de marca');

    // One brand: the name, and no control that pretends to offer a choice.
    $solo = User::factory()->clientOwner(permissions: ['estrategia' => 'read'])->create();

    actingAs($solo)->get(route('portal.home'))
        ->assertOk()
        ->assertDontSee('Cambiar de marca');
});

it('defaults to a brand rather than to nothing', function () {
    [$user, $alea] = personInTwoBrands();

    // No choice made yet: the first by name, so it is the same brand on the
    // next request rather than whatever the database happened to return first.
    expect($user->activeBrand()->is($alea))->toBeTrue();
});

it('switches brand and keeps the choice', function () {
    [$user, , $bruma] = personInTwoBrands();

    actingAs($user)->post(route('portal.brand.switch', $bruma))->assertRedirect();

    expect(session('active_client_id'))->toBe($bruma->id);
});

it('refuses to switch into a brand that is not yours', function () {
    [$user] = personInTwoBrands();

    $somebodyElses = Client::factory()->create(['name' => 'Ajena']);

    // 404, not 403: a brand you are not in should not confirm it exists.
    actingAs($user)->post(route('portal.brand.switch', $somebodyElses))
        ->assertNotFound();
});

/* -------------------------------------------------------------------------
 | Permissions are per brand
 ------------------------------------------------------------------------- */

it('does not leak a grant from one brand into the other', function () {
    [$user, $alea, $bruma] = personInTwoBrands(
        first: ['estrategia' => 'read', 'reuniones' => 'read'],
        second: ['estrategia' => 'read'],
    );

    expect($user->canRead(PortalSection::Reuniones, $alea))->toBeTrue()
        ->and($user->canRead(PortalSection::Reuniones, $bruma))->toBeFalse();
});

it('opens a page in one brand and 404s on it in the other', function () {
    [$user, , $bruma] = personInTwoBrands(
        first: ['estrategia' => 'read', 'reuniones' => 'read'],
        second: ['estrategia' => 'read'],
    );

    actingAs($user)->get(route('portal.reuniones'))->assertOk();

    actingAs($user)->post(route('portal.brand.switch', $bruma));
    actingAs($user)->get(route('portal.reuniones'))->assertNotFound();
});

it('owns one brand and is only a member of the other', function () {
    [$user, , $bruma] = personInTwoBrands();

    // Equipo is what owning MEANS, so it is never in the map — and it must not
    // follow the person across to a brand they merely belong to.
    actingAs($user)->get(route('portal.equipo'))->assertOk();

    actingAs($user)->post(route('portal.brand.switch', $bruma));
    actingAs($user)->get(route('portal.equipo'))->assertNotFound();
});

/* -------------------------------------------------------------------------
 | The session is not a permission
 ------------------------------------------------------------------------- */

it('stops answering for a brand the person was removed from', function () {
    [$user, , $bruma] = personInTwoBrands();

    actingAs($user)->post(route('portal.brand.switch', $bruma));
    expect(session('active_client_id'))->toBe($bruma->id);

    // Removed while they have the portal open. The stale id in the session
    // must not keep resolving — a session outlives a permission change.
    $bruma->users()->detach($user->id);

    actingAs($user)->get(route('portal.home'))->assertOk();

    expect($user->fresh()->activeBrand()?->is($bruma))->toBeFalse();
});

/* -------------------------------------------------------------------------
 | Archiving — the mechanism that must survive
 ------------------------------------------------------------------------- */

it('drops an archived brand from the picker and keeps the other', function () {
    [$user, $alea, $bruma] = personInTwoBrands();

    $alea->delete();

    $user = $user->fresh();

    expect($user->brands->pluck('name')->all())->toBe(['Bruma'])
        ->and($user->activeBrand()->is($bruma))->toBeTrue();
});

it('closes the portal to somebody whose only brand is archived', function () {
    $client = Client::factory()->create();
    $user = User::factory()->clientOwner($client->id, ['estrategia' => 'read'])->create();

    $client->delete();

    actingAs($user->fresh())->get(route('portal.estrategia'))->assertNotFound();
});

it('gives everything back when an archived brand is restored', function () {
    $client = Client::factory()->create();
    $user = User::factory()->clientOwner($client->id, ['estrategia' => 'read'])->create();

    $client->delete();
    $client->restore();

    // Nothing was taken away, so nothing has to be put back: no user row and
    // no membership row was touched by the archiving.
    actingAs($user->fresh())->get(route('portal.estrategia'))->assertOk();
});

/* -------------------------------------------------------------------------
 | Files answer about their own brand
 ------------------------------------------------------------------------- */

it('gates a file on the brand it belongs to, not the one on screen', function () {
    [$user, $alea, $bruma] = personInTwoBrands(
        first: ['brand-assets' => 'read'],
        second: [],
    );

    $aleaFile = BrandAsset::factory()->create(['client_id' => $alea->id]);
    $brumaFile = BrandAsset::factory()->create(['client_id' => $bruma->id]);

    // Looking at Bruma. The answer about Alea's file must not change because
    // of which tab is open — that would read as flakiness, not as a rule.
    actingAs($user)->post(route('portal.brand.switch', $bruma));

    $user = $user->fresh();

    expect($user->canReachBrandAsset($aleaFile))->toBeTrue()
        ->and($user->canReachBrandAsset($brumaFile))->toBeFalse();
});

/* -------------------------------------------------------------------------
 | The assistant thread does not cross brands
 ------------------------------------------------------------------------- */

it('keeps one assistant thread per brand', function () {
    [$user, $alea, $bruma] = personInTwoBrands();

    foreach ([[$alea, 'Los colores de Alea'], [$bruma, 'Los colores de Bruma']] as [$brand, $body]) {
        AssistantMessage::create([
            'user_id' => $user->id,
            'surface' => AssistantMessage::SURFACE_PORTAL,
            'role' => 'user',
            'body' => $body,
            'client_id' => $brand->id,
        ]);
    }

    $aleaThread = AssistantMessage::query()
        ->thread($user->id, AssistantMessage::SURFACE_PORTAL, clientId: $alea->id)
        ->pluck('body');

    expect($aleaThread->all())->toBe(['Los colores de Alea']);
});

it('leaves the dashboard thread crossing brands on purpose', function () {
    // The admin assistant answers "compará Alea con la otra", so its thread is
    // ONE conversation that happens to record which brand each turn named.
    // Scoping it by default would quietly cut it into strands.
    $admin = User::factory()->admin()->create();
    $alea = Client::factory()->create();
    $bruma = Client::factory()->create();

    foreach ([$alea, $bruma] as $brand) {
        AssistantMessage::create([
            'user_id' => $admin->id,
            'surface' => AssistantMessage::SURFACE_ADMIN,
            'role' => 'user',
            'body' => 'Sobre '.$brand->name,
            'client_id' => $brand->id,
        ]);
    }

    $thread = AssistantMessage::query()
        ->thread($admin->id, AssistantMessage::SURFACE_ADMIN)
        ->get();

    expect($thread)->toHaveCount(2);
});

/* -------------------------------------------------------------------------
 | Removing somebody
 ------------------------------------------------------------------------- */

it('removes a person from one brand without closing them out of the other', function () {
    [$member, $alea, $bruma] = personInTwoBrands(
        firstRole: BrandRole::Miembro,
        secondRole: BrandRole::Miembro,
    );

    $owner = User::factory()->clientOwner($alea->id, ['estrategia' => 'read'])->create();

    actingAs($owner)->delete(route('portal.equipo.destroy', $member));

    // ⚠️ The account survives, because it still has Bruma. An owner of one
    // brand must never be able to close somebody out of another.
    expect(User::find($member->id))->not->toBeNull()
        ->and($member->fresh()->brands->pluck('name')->all())->toBe(['Bruma']);
});

it('deletes the account when the brand removed was the last one', function () {
    $client = Client::factory()->create();

    $owner = User::factory()->clientOwner($client->id, ['estrategia' => 'read'])->create();

    $member = User::factory()->clientMember($client->id, ['estrategia' => 'read'])->create();

    actingAs($owner)->delete(route('portal.equipo.destroy', $member));

    expect(User::find($member->id))->toBeNull();
});

/* -------------------------------------------------------------------------
 | Inviting somebody who already has an account
 ------------------------------------------------------------------------- */

it('adds an existing account to a brand instead of refusing it', function () {
    $admin = User::factory()->admin()->create();
    $bruma = Client::factory()->create(['name' => 'Bruma']);

    $existing = User::factory()
        ->clientOwner(permissions: ['estrategia' => 'read'])
        ->create([
            'name' => 'Lucía Paz',
            'email' => 'lucia@ejemplo.com',
        ]);

    $before = $existing->password;

    actingAs($admin)->post(route('admin.clients.users.store', $bruma), [
        'name' => 'Lucía Paz',
        'email' => 'lucia@ejemplo.com',
        'role' => UserRole::ClienteMiembro->value,
        'permissions' => ['estrategia' => ['read']],
    ])->assertRedirect();

    $existing->refresh();

    // One account, two brands — and the password they already had is untouched,
    // because no setup link was sent and none should have been.
    expect(User::where('email', 'lucia@ejemplo.com')->count())->toBe(1)
        ->and($existing->brands)->toHaveCount(2)
        ->and($existing->brands->contains($bruma))->toBeTrue()
        ->and($existing->password)->toBe($before);
});

it('does not let a brand owner attach an address that already has an account', function () {
    // Refused on this side, unlike on Breakfast's: attaching would tell the
    // owner that an account exists on an address they only guessed at.
    $client = Client::factory()->create();
    $owner = User::factory()->clientOwner($client->id, ['estrategia' => 'read'])->create();

    User::factory()->create(['email' => 'ajena@ejemplo.com']);

    actingAs($owner)->post(route('portal.equipo.store'), [
        'name' => 'Quien Sea',
        'email' => 'ajena@ejemplo.com',
        'permissions' => ['estrategia' => ['read']],
    ])->assertSessionHasErrors('email');
});

/* -------------------------------------------------------------------------
 | The columns this replaced are dead
 ------------------------------------------------------------------------- */

it('answers from the membership, and the old columns no longer exist', function () {
    [$user, $alea] = personInTwoBrands(first: ['estrategia' => 'read', 'reuniones' => 'read']);

    // Dropped on 2026-09-15 — docs/multimarca.md step 10. Asserted on the
    // SCHEMA rather than on the model, because a dropped column and a column
    // that merely reads null are indistinguishable from an accessor, and only
    // one of them is what this file set out to prove.
    expect(Schema::hasColumn('users', 'client_id'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'permissions'))->toBeFalse()
        ->and($user->canRead(PortalSection::Reuniones, $alea))->toBeTrue()
        ->and($user->activeBrand()->is($alea))->toBeTrue();
});

it('grants owner-only sections from the membership role', function () {
    [$user, $alea, $bruma] = personInTwoBrands();

    expect($user->brandRole($alea))->toBe(BrandRole::Owner)
        ->and($user->brandRole($bruma))->toBe(BrandRole::Miembro)
        ->and($user->isBrandOwner($alea))->toBeTrue()
        ->and($user->isBrandOwner($bruma))->toBeFalse()
        ->and($user->accessTo(PortalSection::Equipo, $alea))->toBe(AccessLevel::Write)
        ->and($user->accessTo(PortalSection::Equipo, $bruma))->toBeNull();
});
