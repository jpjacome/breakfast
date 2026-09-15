<?php

use App\Actions\EnforceBrandPermissionCeiling;
use App\Enums\AccessLevel;
use App\Enums\PortalSection;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;

use function Pest\Laravel\actingAs;

/** Every grantable section at one level — the shape Breakfast grants an owner. */
function allSections(AccessLevel $level): array
{
    return array_reduce(
        PortalSection::grantable(),
        fn (array $carry, PortalSection $s) => $carry + [$s->value => $level->value],
        [],
    );
}

/**
 * The permissions map actually stored for this person IN THIS BRAND — ACC-01.
 *
 * It lives on the brand_user pivot rather than on the user, so a test that
 * wants to prove a grant was DROPPED AT WRITE TIME — rather than merely
 * clamped when read back — has to look there. The behavioural half of those
 * assertions is canRead(), which is the thing that matters; this is what stops
 * a bad grant sitting in the database waiting for the read rule to be relaxed.
 */
function storedMap(User $user, Client $client): array
{
    $stored = $client->users()->whereKey($user->getKey())->sole()->pivot->permissions;

    if (is_string($stored)) {
        $stored = json_decode($stored, true);
    }

    return is_array($stored) ? $stored : [];
}

/**
 * A brand, its owner, and a member.
 *
 * The owner defaults to Write everywhere so a test that only cares about the
 * member's grant does not have to think about the ceiling above it. Tests about
 * the chain itself pass $ownerPermissions explicitly.
 */
function brandWith(array $permissions = [], ?array $ownerPermissions = null): array
{
    $client = Client::factory()->create();

    $owner = User::factory()->clientOwner($client, $ownerPermissions ?? allSections(AccessLevel::Write))->create();

    $member = User::factory()->clientMember($client, $permissions)->create();

    return [$client, $owner, $member];
}

/* -------------------------------------------------------------------------
 | Reading
 ------------------------------------------------------------------------- */

it('lets a fully granted brand owner into every section', function () {
    [, $owner] = brandWith();

    foreach (PortalSection::cases() as $section) {
        actingAs($owner)->get(route($section->routeName()))->assertOk();
    }
});

it('closes a section to a brand owner Breakfast did not grant them', function () {
    // Owners read the same map members do — the role does not imply access.
    [, $owner] = brandWith(ownerPermissions: ['estrategia' => 'read']);

    actingAs($owner)->get(route('portal.estrategia'))->assertOk();
    actingAs($owner)->get(route('portal.reuniones'))->assertNotFound();
});

it('always gives a brand owner their team, ungranted', function () {
    // Equipo is what being the owner means, so it is not in the map and cannot
    // be taken away by leaving boxes unticked. Billing will join it when
    // Suscripción is built — see docs/suscripciones.md.
    [, $owner] = brandWith(ownerPermissions: []);

    actingAs($owner)->get(route('portal.equipo'))->assertOk();

    expect($owner->canWrite(PortalSection::Equipo))->toBeTrue();
});

it('lets a member into a section they were granted', function () {
    [, , $member] = brandWith(['estrategia' => 'read']);

    actingAs($member)->get(route('portal.estrategia'))->assertOk();
});

it('hides a section the member was not granted, even by direct URL', function () {
    [, , $member] = brandWith(['estrategia' => 'read']);

    actingAs($member)->get(route('portal.brand_assets'))->assertNotFound();
});

it('keeps owner-only sections away from members', function () {
    [, , $member] = brandWith(['estrategia' => 'write']);

    actingAs($member)->get(route('portal.equipo'))->assertNotFound();
});

it('gives everyone the always-on sections without a grant', function () {
    [, , $member] = brandWith();

    // Perfil is the only always-on section left; Ayuda was removed with the
    // sidebar cleanup.
    actingAs($member)->get(route('portal.perfil'))->assertOk();
});

it('leaves ungranted sections out of the sidebar', function () {
    [, , $member] = brandWith(['estrategia' => 'read']);

    $page = actingAs($member)->get(route('portal.home'));

    // Labels, not case names: Estrategia is shown as "Tu marca".
    $page->assertSee('Tu marca');
    $page->assertDontSee('Archivos');
    $page->assertDontSee('Reuniones');
});

/* -------------------------------------------------------------------------
 | Writing
 ------------------------------------------------------------------------- */

it('treats write as covering read but not the reverse', function () {
    // On the enum, because that is the only place the two levels still meet:
    // no client holds Write on a grantable section, so the ordering is
    // exercised by Perfil and by Breakfast staff rather than by a grant.
    expect(AccessLevel::Write->covers(AccessLevel::Read))->toBeTrue()
        ->and(AccessLevel::Write->covers(AccessLevel::Write))->toBeTrue()
        ->and(AccessLevel::Read->covers(AccessLevel::Read))->toBeTrue()
        ->and(AccessLevel::Read->covers(AccessLevel::Write))->toBeFalse();
});

it('gives a client read on a granted section and no write anywhere in it', function () {
    [, , $member] = brandWith(['estrategia' => 'read', 'brand-assets' => 'read']);

    expect($member->canRead(PortalSection::Estrategia))->toBeTrue()
        ->and($member->canWrite(PortalSection::Estrategia))->toBeFalse()
        ->and($member->canRead(PortalSection::BrandAssets))->toBeTrue()
        ->and($member->canWrite(PortalSection::BrandAssets))->toBeFalse()
        // Perfil is where a client still writes: it is always-on, not granted.
        ->and($member->canWrite(PortalSection::Perfil))->toBeTrue();
});

it('shows no edit control on a granted section', function () {
    // Not even a greyed-out one. Every grantable section is read-only for a
    // client — Breakfast writes a brand, the brand reads it — so a disabled
    // "Editar" would advertise a permission nobody can be given.
    [, , $member] = brandWith(['estrategia' => 'read', 'brand-assets' => 'read']);

    actingAs($member)->get(route('portal.estrategia'))
        ->assertDontSee('Editar', false)
        ->assertDontSee('sólo lectura', false);

    actingAs($member)->get(route('portal.brand_assets'))
        ->assertDontSee('sólo lectura', false);
});

it('reads a stored write back as read', function () {
    // A row from when the grid offered Editar. It is clamped on the way out,
    // so an old grant cannot outlive the column it came from.
    [, , $member] = brandWith(['estrategia' => 'write']);

    expect($member->accessTo(PortalSection::Estrategia))->toBe(AccessLevel::Read)
        ->and($member->canWrite(PortalSection::Estrategia))->toBeFalse();
});

/* -------------------------------------------------------------------------
 | The owner handing out access
 ------------------------------------------------------------------------- */

it('lets the owner invite a member with a partial grant', function () {
    [$client, $owner] = brandWith();

    actingAs($owner)->post(route('portal.equipo.store'), [
        'name' => 'Diego Ruiz',
        'email' => 'diego@lamarca.com',
        'permissions' => [
            'estrategia' => ['read'],
            'brand-assets' => ['read', 'write'],
        ],
    ])->assertRedirect();

    $invited = User::where('email', 'diego@lamarca.com')->sole();

    expect($invited->client_id)->toBe($client->id)
        ->and($invited->role)->toBe(UserRole::ClienteMiembro)
        ->and($invited->accessTo(PortalSection::Estrategia))->toBe(AccessLevel::Read)
        // Posted as read+write, stored as Read: a grantable section tops out
        // there for everyone, because the client portal has no write path.
        ->and($invited->accessTo(PortalSection::BrandAssets))->toBe(AccessLevel::Read)
        ->and($invited->accessTo(PortalSection::Reuniones))->toBeNull();
});

it('clamps a hand-posted write tick down to read', function () {
    // The grid has one box per section now, so this only arrives from a
    // hand-edited payload. It is clamped rather than rejected — asking for
    // more than exists is not an error worth an error page.
    [, $owner] = brandWith();

    actingAs($owner)->post(route('portal.equipo.store'), [
        'name' => 'Ana Prieto',
        'email' => 'ana@lamarca.com',
        'permissions' => ['reuniones' => ['write']],
    ]);

    expect(User::where('email', 'ana@lamarca.com')->sole()->accessTo(PortalSection::Reuniones))
        ->toBe(AccessLevel::Read);
});

it('ignores an attempt to grant an owner-only section', function () {
    // Even a fully-granted owner cannot delegate Equipo: it is not grantable
    // at all, so grantCeiling() returns null for it.
    [$client, $owner] = brandWith();

    actingAs($owner)->post(route('portal.equipo.store'), [
        'name' => 'Bruno Salas',
        'email' => 'bruno@lamarca.com',
        'permissions' => [
            'equipo' => ['write'],
        ],
    ]);

    $invited = User::where('email', 'bruno@lamarca.com')->sole();

    expect(storedMap($invited, $client))->toBe([])
        ->and($invited->canRead(PortalSection::Equipo))->toBeFalse();
});

it('lets the owner change an existing member and take access away', function () {
    [, $owner, $member] = brandWith(['estrategia' => 'write', 'reuniones' => 'read']);

    actingAs($owner)->put(route('portal.equipo.update', $member), [
        'permissions' => ['estrategia' => ['read']],
    ])->assertRedirect();

    $member->refresh();

    expect($member->accessTo(PortalSection::Estrategia))->toBe(AccessLevel::Read)
        ->and($member->accessTo(PortalSection::Reuniones))->toBeNull();
});

it('stops a member from reaching the team endpoints at all', function () {
    [, $owner, $member] = brandWith(['estrategia' => 'write']);

    actingAs($member)->post(route('portal.equipo.store'), [
        'name' => 'Colado',
        'email' => 'colado@lamarca.com',
    ])->assertNotFound();

    actingAs($member)->put(route('portal.equipo.update', $owner), [])->assertNotFound();
});

it('stops an owner from touching another brand\'s member', function () {
    [, $owner] = brandWith();
    [, , $otherMember] = brandWith(['estrategia' => 'read']);

    actingAs($owner)->put(route('portal.equipo.update', $otherMember), [
        'permissions' => ['reuniones' => ['write']],
    ])->assertNotFound();

    actingAs($owner)->delete(route('portal.equipo.destroy', $otherMember))->assertNotFound();
});

it('stops an owner from deleting a co-owner', function () {
    $client = Client::factory()->create();
    $owner = User::factory()->clientOwner($client)->create();
    $coOwner = User::factory()->clientOwner($client)->create();

    actingAs($owner)->delete(route('portal.equipo.destroy', $coOwner))->assertForbidden();
});

/* -------------------------------------------------------------------------
 | The chain: Breakfast -> owner -> member, narrowing each step
 ------------------------------------------------------------------------- */

it('caps a grant at read even when the owner is stored at write', function () {
    // A grantable section tops out at Read for everyone, so an owner whose
    // stored map still says 'write' — a legacy row — cannot pass that on.
    [, $owner] = brandWith(ownerPermissions: ['estrategia' => 'read', 'estrategia' => 'write']);

    actingAs($owner)->post(route('portal.equipo.store'), [
        'name' => 'Diego Ruiz',
        'email' => 'diego@lamarca.com',
        'permissions' => [
            'estrategia' => ['read', 'write'],
            'estrategia' => ['read', 'write'],
        ],
    ]);

    $invited = User::where('email', 'diego@lamarca.com')->sole();

    expect($invited->accessTo(PortalSection::Estrategia))->toBe(AccessLevel::Read)
        ->and($invited->accessTo(PortalSection::Estrategia))->toBe(AccessLevel::Read);
});

it('drops a section the owner does not hold at all', function () {
    [$client, $owner] = brandWith(ownerPermissions: ['estrategia' => 'read']);

    actingAs($owner)->post(route('portal.equipo.store'), [
        'name' => 'Ana Prieto',
        'email' => 'ana@lamarca.com',
        'permissions' => ['reuniones' => ['read', 'write']],
    ]);

    $invited = User::where('email', 'ana@lamarca.com')->sole();

    expect(storedMap($invited, $client))->toBe([])
        ->and($invited->canRead(PortalSection::Reuniones))->toBeFalse();
});

it('offers an owner only the sections they can pass on', function () {
    [, $owner] = brandWith(ownerPermissions: ['estrategia' => 'read', 'brand-assets' => 'read']);

    $page = actingAs($owner)->get(route('portal.equipo'));

    $page->assertSee('permissions[estrategia][]', escape: false);
    $page->assertSee('permissions[brand-assets][]', escape: false);
    $page->assertDontSee('permissions[reuniones][]', escape: false);
});

it('lowers a member stored at write down to read', function () {
    // Legacy rows from when the grid offered Editar. Any write to the owner
    // re-applies the ceiling, and the ceiling is Read.
    $admin = User::factory()->admin()->create();

    [$client, $owner, $member] = brandWith(
        ['estrategia' => 'write', 'brand-assets' => 'write'],
        ['estrategia' => 'write', 'brand-assets' => 'write'],
    );

    actingAs($admin)->put(route('admin.clients.users.update', [$client, $owner]), [
        'permissions' => [
            'estrategia' => ['read'],
            'brand-assets' => ['read'],
        ],
    ])->assertRedirect();

    $member->refresh();

    expect($member->accessTo(PortalSection::Estrategia))->toBe(AccessLevel::Read)
        ->and($member->accessTo(PortalSection::BrandAssets))->toBe(AccessLevel::Read);
});

it('revokes from the team when the owner loses a section outright', function () {
    $admin = User::factory()->admin()->create();

    [$client, $owner, $member] = brandWith(
        ['reuniones' => 'read'],
        ['reuniones' => 'write'],
    );

    actingAs($admin)->put(route('admin.clients.users.update', [$client, $owner]), [
        'permissions' => [],
    ]);

    expect($member->refresh()->accessTo(PortalSection::Reuniones))->toBeNull();
});

it('leaves the team alone when Breakfast edits a member rather than an owner', function () {
    $admin = User::factory()->admin()->create();

    [$client, , $member] = brandWith(['reuniones' => 'read']);

    $other = User::factory()->clientMember($client, ['reuniones' => 'write'])->create();

    actingAs($admin)->put(route('admin.clients.users.update', [$client, $member]), [
        'permissions' => ['reuniones' => ['read', 'write']],
    ]);

    // Editing one member is not a ceiling change, so the other's row is not
    // rewritten. Asserted on the STORED PIVOT rather than accessTo(), which
    // clamps a legacy 'write' to Read on the way out — the point here is that
    // nothing wrote to this member at all.
    expect(storedMap($other, $client))->toBe(['reuniones' => 'write']);
});

it('changes nothing when the ceiling is re-applied to a consistent brand', function () {
    [$client] = brandWith(['estrategia' => 'read']);

    $lowered = app(EnforceBrandPermissionCeiling::class)->handle($client);

    expect($lowered)->toBe(0);
});

/* -------------------------------------------------------------------------
 | Edge cases in the model itself
 ------------------------------------------------------------------------- */

it('fails closed for a client user with no brand', function () {
    // No membership at all, which a bare factory now says plainly — it used to
    // take an invisible brand of its own from users.client_id.
    $stray = User::factory()->create(['role' => UserRole::ClienteMiembro]);

    expect($stray->canRead(PortalSection::Estrategia))->toBeFalse()
        ->and($stray->visibleSections())->toBe([]);
});

it('ignores a level that is no longer a valid AccessLevel', function () {
    [, , $member] = brandWith(['estrategia' => 'superuser']);

    expect($member->accessTo(PortalSection::Estrategia))->toBeNull();
});
