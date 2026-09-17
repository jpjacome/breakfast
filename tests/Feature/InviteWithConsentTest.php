<?php

declare(strict_types=1);

use App\Actions\InviteUserToClient;
use App\Enums\UserRole;
use App\Models\BrandInvitation;
use App\Models\Client;
use App\Models\User;
use App\Notifications\BrandMembershipInvitation;
use App\Notifications\ClientInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;

/**
 * Queued item B — an owner asks, they do not add.
 *
 * ⚠️ THE THING BEING PROTECTED IS NOT THE BRAND, IT IS THE ADDRESS. A brand
 * owner must not be able to discover whether somebody has an account here by
 * typing addresses into the invite form. The app used to refuse a known address
 * with "ya existe una cuenta con ese correo" — which is the answer it was
 * trying not to give.
 */
beforeEach(function () {
    Notification::fake();

    $this->client = Client::factory()->create(['name' => 'Panadería Sur']);
    $this->owner = User::factory()->clientOwner($this->client->id, ['estrategia' => 'read'])->create();
});

function inviteAs(User $owner, string $email): TestResponse
{
    return actingAs($owner)->post(route('portal.equipo.store'), [
        'name' => 'Quien Sea',
        'email' => $email,
        'permissions' => ['estrategia' => ['read']],
    ]);
}

it('says the same thing whether the address has an account or not', function () {
    /*
     * ⚠️ THE TEST THIS ITEM EXISTS FOR. Two invites, two different underlying
     * outcomes, one indistinguishable answer. If this ever fails, the invite
     * form has become an account-enumeration oracle again.
     */
    User::factory()->create(['email' => 'conocida@ejemplo.com']);

    inviteAs($this->owner, 'conocida@ejemplo.com');
    $existing = str_replace('conocida@ejemplo.com', 'X', (string) session('status'));

    inviteAs($this->owner, 'nueva@ejemplo.com');
    $new = str_replace('nueva@ejemplo.com', 'X', (string) session('status'));

    // The address itself differs, of course — the owner typed it. Everything
    // around it must not.
    expect($existing)->toBe($new)
        ->and($existing)->not->toBeEmpty()
        // ⚠️ And it names the ADDRESS, never a person. A name the owner did not
        // type appearing in the answer would say the account was already there,
        // which is the same leak wearing a different sentence.
        ->and($existing)->not->toContain('Quien Sea');
});

it('writes nothing to the pivot for an address that already has an account', function () {
    $stranger = User::factory()->create(['email' => 'ajena@ejemplo.com']);

    inviteAs($this->owner, 'ajena@ejemplo.com')->assertSessionHasNoErrors();

    expect($stranger->fresh()->brands()->where('clients.id', $this->client->id)->exists())
        ->toBeFalse();

    Notification::assertSentTo($stranger, BrandMembershipInvitation::class);
});

it('still creates the account outright for an address with none', function () {
    // Nobody to ask. Creating the account IS the invitation, and the setup link
    // is the consent — only whoever reads that mailbox can use it.
    inviteAs($this->owner, 'nueva@ejemplo.com');

    $created = User::where('email', 'nueva@ejemplo.com')->sole();

    expect($created->brands()->where('clients.id', $this->client->id)->exists())->toBeTrue();
    Notification::assertSentTo($created, ClientInvitation::class);
});

it('lets Breakfast attach an existing person directly', function () {
    // Nothing to keep from them: they administer every brand and every account,
    // and somebody has to be able to do this without a round trip.
    $person = User::factory()->create(['email' => 'conocida@ejemplo.com']);

    app(InviteUserToClient::class)->handle(
        client: $this->client,
        name: 'Quien Sea',
        email: 'conocida@ejemplo.com',
        role: UserRole::ClienteMiembro,
    );

    expect($person->fresh()->brands()->where('clients.id', $this->client->id)->exists())
        ->toBeTrue()
        ->and(BrandInvitation::count())->toBe(0);
});

it('joins the brand only when the invited person accepts', function () {
    $stranger = User::factory()->create(['email' => 'ajena@ejemplo.com']);
    inviteAs($this->owner, 'ajena@ejemplo.com');

    $invitation = BrandInvitation::sole();

    actingAs($stranger)
        ->post(route('portal.invitaciones.accept', $invitation->token))
        ->assertRedirect(route('portal.home'));

    expect($stranger->fresh()->brands()->where('clients.id', $this->client->id)->exists())
        ->toBeTrue()
        ->and($invitation->fresh()->isPending())->toBeFalse()
        // Dropped into the brand they just joined, or accepting reads as having
        // done nothing.
        ->and(session('active_client_id'))->toBe($this->client->id);
});

it('joins nothing when they decline, and tells nobody', function () {
    $stranger = User::factory()->create(['email' => 'ajena@ejemplo.com']);
    inviteAs($this->owner, 'ajena@ejemplo.com');

    $invitation = BrandInvitation::sole();

    actingAs($stranger)->post(route('portal.invitaciones.decline', $invitation->token));

    expect($stranger->fresh()->brands()->where('clients.id', $this->client->id)->exists())
        ->toBeFalse()
        ->and($invitation->fresh()->declined_at)->not->toBeNull();

    // ⚠️ The owner is not notified. A refusal that reports back turns "no" into
    // a conversation, which is most of the reason somebody accepts an
    // invitation they did not want.
    Notification::assertNothingSentTo($this->owner);
});

it("404s on somebody else's token", function () {
    User::factory()->create(['email' => 'ajena@ejemplo.com']);
    inviteAs($this->owner, 'ajena@ejemplo.com');

    $token = BrandInvitation::sole()->token;
    $nosy = User::factory()->create();

    // A forwarded link must not tell whoever opens it which brand invited whom.
    actingAs($nosy)->get(route('portal.invitaciones.show', $token))->assertNotFound();
    actingAs($nosy)->post(route('portal.invitaciones.accept', $token))->assertNotFound();
});

it('404s on an expired or already answered invitation', function () {
    $stranger = User::factory()->create(['email' => 'ajena@ejemplo.com']);
    inviteAs($this->owner, 'ajena@ejemplo.com');

    $invitation = BrandInvitation::sole();
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();

    actingAs($stranger)->get(route('portal.invitaciones.show', $invitation->token))
        ->assertNotFound();

    // Answered, not expired — same 404, and it does not say which.
    $invitation->forceFill(['expires_at' => now()->addDay(), 'accepted_at' => now()])->save();

    actingAs($stranger)->get(route('portal.invitaciones.show', $invitation->token))
        ->assertNotFound();
});

it('does not mint a second token for the same address twice', function () {
    // Two live tokens means a second mail that looks like the first, and an
    // accepted invitation with a twin still open.
    User::factory()->create(['email' => 'ajena@ejemplo.com']);

    inviteAs($this->owner, 'ajena@ejemplo.com');
    inviteAs($this->owner, 'ajena@ejemplo.com');

    expect(BrandInvitation::where('email', 'ajena@ejemplo.com')->count())->toBe(1);
});

it('shows the invited person what they would be joining', function () {
    $stranger = User::factory()->create(['email' => 'ajena@ejemplo.com']);
    inviteAs($this->owner, 'ajena@ejemplo.com');

    actingAs($stranger)
        ->get(route('portal.invitaciones.show', BrandInvitation::sole()->token))
        ->assertOk()
        ->assertSee('Panadería Sur', false)
        // Saying no has to be as easy as saying yes, or the consent is not one.
        ->assertSee('No, gracias', false);
});
