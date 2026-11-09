<?php

use App\Actions\InviteUserToClient;
use App\Enums\BrandRole;
use App\Enums\PortalSection;
use App\Enums\UserRole;
use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\User;
use App\Notifications\ClientInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('an admin can create a user for a client', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    $this->post(route('admin.clients.users.store', $client), [
        'name' => 'María García',
        'email' => 'maria@lamarca.test',
        'role' => UserRole::ClienteOwner->value,
    ])->assertRedirect();

    $user = User::firstWhere('email', 'maria@lamarca.test');

    // The brand is the MEMBERSHIP, not a column — users.client_id was dropped
    // on 2026-09-15 (docs/multimarca.md step 10).
    expect($user)->not->toBeNull()
        ->and($user->brands->contains($client))->toBeTrue()
        ->and($user->role)->toBe(UserRole::ClienteOwner);
});

test('the new user gets a link to set their own password', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    $this->post(route('admin.clients.users.store', $client), [
        'name' => 'Nuevo',
        'email' => 'nuevo@lamarca.test',
        'role' => UserRole::ClienteMiembro->value,
    ]);

    Notification::assertSentTo(
        User::firstWhere('email', 'nuevo@lamarca.test'),
        ClientInvitation::class,
    );
});

test('the temporary password is flashed once and actually works', function () {
    Notification::fake();

    $this->actingAs($admin = User::factory()->admin()->create());
    $client = Client::factory()->create();

    $response = $this->post(route('admin.clients.users.store', $client), [
        'name' => 'Temp',
        'email' => 'temp@lamarca.test',
        'role' => UserRole::ClienteMiembro->value,
    ]);

    $password = session('temp_password');
    expect($password)->toBeString()->not->toBeEmpty();

    // It must be a real credential, not decoration.
    $user = User::firstWhere('email', 'temp@lamarca.test');
    expect(Hash::check($password, $user->password))->toBeTrue();
});

test('breakfast roles cannot be assigned through the client form', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    foreach ([UserRole::Admin, UserRole::Equipo] as $role) {
        $this->post(route('admin.clients.users.store', $client), [
            'name' => 'Escalada',
            'email' => "escalada-{$role->value}@lamarca.test",
            'role' => $role->value,
        ])->assertSessionHasErrors('role');
    }

    expect(User::where('role', UserRole::Admin)->count())->toBe(1); // just the acting admin
    expect(User::where('role', UserRole::Equipo)->count())->toBe(0);
});

test('an address that already has an account is added to the brand, not refused', function () {
    // ⚠️ This used to assert a validation error, and that was right while an
    // account belonged to exactly one brand. ACC-01 inverted it: the same
    // person working with two brands is the case this app now has to serve,
    // and refusing the address was what forced them into a second one.
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    $existing = User::factory()->create(['email' => 'tomado@lamarca.test']);

    $this->post(route('admin.clients.users.store', $client), [
        'name' => 'Otro',
        'email' => 'tomado@lamarca.test',
        'role' => UserRole::ClienteMiembro->value,
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'tomado@lamarca.test')->count())->toBe(1)
        ->and($existing->fresh()->brands->contains($client))->toBeTrue();

    // No "create your password" mail: they already have one.
    Notification::assertNothingSent();
});

test('a client user cannot create accounts', function () {
    $client = Client::factory()->create();
    $this->actingAs(User::factory()->clientOwner($client)->create());

    $this->post(route('admin.clients.users.store', $client), [
        'name' => 'Colado',
        'email' => 'colado@lamarca.test',
        'role' => UserRole::ClienteMiembro->value,
    ])->assertNotFound();

    expect(User::where('email', 'colado@lamarca.test')->exists())->toBeFalse();
});

test('a user cannot be managed through another client', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());

    $owner = Client::factory()->create();
    $other = Client::factory()->create();
    $user = User::factory()->clientOwner($owner)->create();

    $this->post(route('admin.clients.users.resend', [$other, $user]))->assertNotFound();
    $this->delete(route('admin.clients.users.destroy', [$other, $user]))->assertNotFound();

    expect(User::find($user->id))->not->toBeNull();
});

test('an admin can remove a client user from a brand', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $client = Client::factory()->create();
    $user = User::factory()->clientOwner($client)->create();

    $this->delete(route('admin.clients.users.destroy', [$client, $user]))->assertRedirect();

    // The MEMBERSHIP goes. The account does not — removal stopped deleting
    // accounts on 2026-09-21; see the two tests at the bottom of this file.
    expect($user->fresh()->brands)->toHaveCount(0)
        ->and(User::find($user->id))->not->toBeNull();

    // The admin is not in this brand, so the route 404s — assert the account
    // survives either way.
    $this->delete(route('admin.clients.users.destroy', [$client, $admin]))->assertNotFound();
    expect(User::find($admin->id))->not->toBeNull();
});

test('a created user can sign in and lands on the portal', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    $this->post(route('admin.clients.users.store', $client), [
        'name' => 'Ingresa',
        'email' => 'ingresa@lamarca.test',
        'role' => UserRole::ClienteOwner->value,
    ]);

    $password = session('temp_password');

    auth()->logout();
    $this->flushSession();

    $this->post('/login', ['email' => 'ingresa@lamarca.test', 'password' => $password])
        ->assertRedirect(route('portal.home'));

    $this->assertAuthenticated();
});

/* --- Breakfast staff are not brand members -------------------------------- */

/** A membership row written the way the app used to allow, for the removal tests. */
function attachAsLegacyMember(Client $client, User $user): void
{
    // Straight to the pivot on purpose: InviteUserToClient::attach() refuses a
    // staff account now, so the only way to reproduce a row created before that
    // rule is to write it the way the factory does.
    $client->users()->attach($user->id, [
        'role' => 'miembro',
        'permissions' => json_encode((object) []),
    ]);
}

test('a breakfast address cannot be added to a brand', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create(['email' => 'admin@breakfast.test']);
    $this->actingAs($admin);
    $client = Client::factory()->create();

    // The role picker only offers client roles, which is not enough: the role
    // applies to an account being created, and an existing address is attached
    // with whatever role it already has.
    $this->post(route('admin.clients.users.store', $client), [
        'name' => 'Yo mismo',
        'email' => 'admin@breakfast.test',
        'role' => UserRole::ClienteOwner->value,
    ])->assertSessionHasErrors('email');

    expect($admin->fresh()->brands)->toHaveCount(0);
});

test('an equipo address cannot be added either', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $staff = User::factory()->equipo()->create(['email' => 'equipo@breakfast.test']);

    $this->post(route('admin.clients.users.store', $client), [
        'name' => 'Compañera',
        'email' => 'equipo@breakfast.test',
        'role' => UserRole::ClienteMiembro->value,
    ])->assertSessionHasErrors('email');

    expect($staff->fresh()->brands)->toHaveCount(0);
});

test('the action itself refuses to attach staff, whatever calls it', function () {
    $client = Client::factory()->create();
    $staff = User::factory()->admin()->create();

    // The backstop under the field error: one class writes brand_user, so the
    // rule is enforced where the row is written and not only where it is asked
    // for.
    expect(fn () => app(InviteUserToClient::class)
        ->attach($client, $staff, BrandRole::Miembro))
        ->toThrow(InvalidArgumentException::class);

    expect($staff->fresh()->brands)->toHaveCount(0);
});

test('an admin attached to a brand can take themselves off it', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $client = Client::factory()->create();
    attachAsLegacyMember($client, $admin);

    // ⚠️ THE BUG THIS PINS. The guard here was a flat 403 on yourself, so an
    // admin who had attached their own address could never take it off: every
    // attempt was their own id. The row was unremovable through the app that
    // created it.
    $this->delete(route('admin.clients.users.destroy', [$client, $admin]))
        ->assertRedirect();

    expect($admin->fresh()->brands)->toHaveCount(0)
        // Detaching is not deleting. The account is still there.
        ->and(User::find($admin->id))->not->toBeNull();
});

test('detaching a staff member never deletes the account', function () {
    $this->actingAs(User::factory()->admin()->create());

    $client = Client::factory()->create();
    $staff = User::factory()->equipo()->create();
    attachAsLegacyMember($client, $staff);

    // Their last brand goes, and before this that emptiness deleted the
    // account — a staff account lives on users.role, not on membership, so
    // "no brands left" says nothing about whether it should exist.
    $this->delete(route('admin.clients.users.destroy', [$client, $staff]))
        ->assertRedirect();

    expect(User::find($staff->id))->not->toBeNull()
        ->and($staff->fresh()->brands)->toHaveCount(0);
});

test('a client user on their last brand is locked out, not deleted', function () {
    $this->actingAs(User::factory()->admin()->create());

    $client = Client::factory()->create();
    $user = User::factory()->clientOwner($client)->create();

    /*
     * ⚠️ THIS ASSERTED THE OPPOSITE UNTIL 2026-09-21. Removal used to end in
     * `$user->delete()` when no brands were left, and `User` has no
     * SoftDeletes, so it was permanent and cascaded their whole history. The
     * button could not say which of the two it would do.
     */
    $this->delete(route('admin.clients.users.destroy', [$client, $user]))
        ->assertRedirect();

    expect(User::find($user->id))->not->toBeNull()
        ->and($user->fresh()->brands)->toHaveCount(0)
        // Locked out rather than erased: accessTo() fails closed with no brand.
        ->and($user->fresh()->accessTo(PortalSection::Estrategia))->toBeNull();
});

test('their history survives the removal', function () {
    $this->actingAs(User::factory()->admin()->create());

    $client = Client::factory()->create();
    $user = User::factory()->clientOwner($client)->create();

    AssistantMessage::create([
        'user_id' => $user->id,
        'surface' => 'portal',
        'role' => 'user',
        'body' => '¿qué colores tenemos?',
    ]);

    $this->delete(route('admin.clients.users.destroy', [$client, $user]));

    // assistant_messages.user_id is cascadeOnDelete, so this is what the old
    // delete actually destroyed - conversations, folders, files and passkeys
    // went the same way.
    expect(AssistantMessage::where('user_id', $user->id)->count())->toBe(1);
});

test('an archived brand does not strand somebody out of existence', function () {
    $this->actingAs(User::factory()->admin()->create());

    $archived = Client::factory()->create();
    $active = Client::factory()->create();
    $user = User::factory()->clientOwner($archived)->create();
    app(InviteUserToClient::class)->attach($active, $user, BrandRole::Miembro);

    // Archiving is a soft delete, and brands() excludes trashed rows - so this
    // person now counts as having ONE brand while holding two pivot rows.
    $archived->delete();

    /*
     * ⚠️ THE SHARPEST VERSION OF THE OLD BUG. Removing them from the remaining
     * brand made brands()->count() zero even though the membership on the
     * archived brand was still sitting there - so the account was deleted, and
     * restoring that brand would have restored a membership pointing at a user
     * who no longer existed.
     */
    $this->delete(route('admin.clients.users.destroy', [$active, $user]))
        ->assertRedirect();

    expect(User::find($user->id))->not->toBeNull();

    // And restoring the brand gives them back exactly what they had.
    $archived->restore();

    expect($user->fresh()->brands->pluck('id')->all())->toBe([$archived->id]);
});
