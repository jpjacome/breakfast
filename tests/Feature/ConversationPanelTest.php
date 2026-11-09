<?php

declare(strict_types=1);

use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationFolder;
use App\Models\User;

/**
 * Item 5 — the history panel's endpoints.
 *
 * ⚠️ MOST OF THIS FILE IS SCOPING, not features. A conversation id arrives from
 * the browser on every turn and on every click in the panel, so it is the one
 * thing in this feature somebody could hand-post. Every one of these asserts a
 * 404 rather than a 403, which is the house rule for a resource somebody should
 * not learn exists (CLAUDE.md §6).
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

/** One conversation on one surface, belonging to one person. */
function conversationFor(User $user, string $surface, ?Client $client = null): Conversation
{
    return Conversation::create([
        'user_id' => $user->id,
        'surface' => $surface,
        'client_id' => $client?->id,
    ]);
}

// ---------------------------------------------------------------- the list

it('lists a person conversations, folders and archive', function () {
    $folder = ConversationFolder::create([
        'user_id' => $this->admin->id,
        'surface' => AssistantMessage::SURFACE_ADMIN,
        'name' => 'Campañas',
    ]);

    $open = conversationFor($this->admin, AssistantMessage::SURFACE_ADMIN);
    $open->forceFill(['title' => 'Una abierta', 'folder_id' => $folder->id])->save();

    $filed = conversationFor($this->admin, AssistantMessage::SURFACE_ADMIN);
    $filed->forceFill(['title' => 'Una archivada', 'archived_at' => now()])->save();

    $response = $this->actingAs($this->admin)
        ->getJson(route('admin.conversations.index'))
        ->assertOk();

    expect($response->json('folders.0.name'))->toBe('Campañas')
        ->and($response->json('conversations'))->toHaveCount(1)
        ->and($response->json('conversations.0.title'))->toBe('Una abierta')
        ->and($response->json('conversations.0.folder'))->toBe($folder->id)
        ->and($response->json('archived'))->toHaveCount(1)
        ->and($response->json('archived.0.title'))->toBe('Una archivada');
});

it('never lists somebody else conversations', function () {
    $other = User::factory()->admin()->create();
    conversationFor($other, AssistantMessage::SURFACE_ADMIN);

    $this->actingAs($this->admin)
        ->getJson(route('admin.conversations.index'))
        ->assertOk()
        ->assertJsonCount(0, 'conversations');
});

// -------------------------------------------------------------- the scoping

it('404s on another person conversation', function () {
    $other = User::factory()->admin()->create();
    $theirs = conversationFor($other, AssistantMessage::SURFACE_ADMIN);

    $this->actingAs($this->admin)
        ->getJson(route('admin.conversations.show', $theirs))
        ->assertNotFound();
});

/**
 * ⚠️ THE SURFACE IS PART OF THE CHECK. Without it a dashboard id posted to the
 * portal's endpoint would load Breakfast's portfolio turns into a client's
 * screen — the one thing the client's assistant is forbidden to do.
 */
it('404s when a conversation is asked for on the wrong surface', function () {
    $client = Client::factory()->create();
    $user = User::factory()->create();
    $user->brands()->attach($client, ['role' => 'owner']);

    $dashboard = conversationFor($this->admin, AssistantMessage::SURFACE_ADMIN);

    $this->actingAs($user)
        ->getJson(route('portal.conversations.show', $dashboard))
        ->assertNotFound();
});

/** ⚠️ ACC-01: one account, many brands. A thread belongs to one of them. */
it('404s on a conversation belonging to another of your own brands', function () {
    $one = Client::factory()->create();
    $two = Client::factory()->create();

    $user = User::factory()->create();
    $user->brands()->attach($one, ['role' => 'owner']);
    $user->brands()->attach($two, ['role' => 'owner']);

    $theirs = conversationFor($user, AssistantMessage::SURFACE_PORTAL, $two);

    // Working in brand one; asking for brand two's thread.
    $this->actingAs($user)
        ->withSession(['active_client_id' => $one->id])
        ->getJson(route('portal.conversations.show', $theirs))
        ->assertNotFound();
});

// --------------------------------------------------------------- the writes

it('starts a conversation, optionally inside a folder', function () {
    $folder = ConversationFolder::create([
        'user_id' => $this->admin->id,
        'surface' => AssistantMessage::SURFACE_ADMIN,
        'name' => 'Campañas',
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('admin.conversations.store'), ['folder' => $folder->id])
        ->assertCreated()
        ->assertJsonPath('folder', $folder->id)
        // Untitled until the first question names it — see titleFrom().
        ->assertJsonPath('title', 'Conversación nueva');
});

it('renames, files and archives from one endpoint', function () {
    $conversation = conversationFor($this->admin, AssistantMessage::SURFACE_ADMIN);
    $folder = ConversationFolder::create([
        'user_id' => $this->admin->id,
        'surface' => AssistantMessage::SURFACE_ADMIN,
        'name' => 'Campañas',
    ]);

    $this->actingAs($this->admin)
        ->patchJson(route('admin.conversations.update', $conversation), [
            'title' => 'La del claim',
            'folder' => $folder->id,
            'archived' => true,
        ])
        ->assertOk()
        ->assertJsonPath('title', 'La del claim')
        ->assertJsonPath('folder', $folder->id)
        ->assertJsonPath('archived', true);
});

/**
 * ⚠️ ABSENT IS NOT NULL. A rename that omits `folder` must leave the
 * conversation where it is — otherwise correcting a title quietly empties
 * somebody's folders.
 */
it('leaves the folder alone when a rename does not mention it', function () {
    $folder = ConversationFolder::create([
        'user_id' => $this->admin->id,
        'surface' => AssistantMessage::SURFACE_ADMIN,
        'name' => 'Campañas',
    ]);

    $conversation = conversationFor($this->admin, AssistantMessage::SURFACE_ADMIN);
    $conversation->forceFill(['folder_id' => $folder->id])->save();

    $this->actingAs($this->admin)
        ->patchJson(route('admin.conversations.update', $conversation), ['title' => 'Otro nombre'])
        ->assertOk()
        ->assertJsonPath('folder', $folder->id);
});

it('takes a conversation out of its folder when folder is null', function () {
    $folder = ConversationFolder::create([
        'user_id' => $this->admin->id,
        'surface' => AssistantMessage::SURFACE_ADMIN,
        'name' => 'Campañas',
    ]);

    $conversation = conversationFor($this->admin, AssistantMessage::SURFACE_ADMIN);
    $conversation->forceFill(['folder_id' => $folder->id])->save();

    $this->actingAs($this->admin)
        ->patchJson(route('admin.conversations.update', $conversation), ['folder' => null])
        ->assertOk()
        ->assertJsonPath('folder', null);
});

it('soft deletes rather than destroying the record', function () {
    $conversation = conversationFor($this->admin, AssistantMessage::SURFACE_ADMIN);

    $this->actingAs($this->admin)
        ->deleteJson(route('admin.conversations.destroy', $conversation))
        ->assertOk();

    expect(Conversation::withTrashed()->find($conversation->id))->not->toBeNull()
        ->and(Conversation::find($conversation->id))->toBeNull();
});

// --------------------------------------------------------------- the folders

/**
 * ⚠️ THE MOST EXPENSIVE MISTAKE THIS PANEL COULD MAKE. Deleting a folder must
 * never delete the conversations in it — that is somebody losing a month of
 * work by tidying up, one click away from an ordinary action.
 */
it('keeps the conversations when their folder is deleted', function () {
    $folder = ConversationFolder::create([
        'user_id' => $this->admin->id,
        'surface' => AssistantMessage::SURFACE_ADMIN,
        'name' => 'Campañas',
    ]);

    $conversation = conversationFor($this->admin, AssistantMessage::SURFACE_ADMIN);
    $conversation->forceFill(['folder_id' => $folder->id])->save();

    $this->actingAs($this->admin)
        ->deleteJson(route('admin.conversations.folders.destroy', $folder))
        ->assertOk();

    expect(Conversation::find($conversation->id))->not->toBeNull()
        ->and(Conversation::find($conversation->id)->folder_id)->toBeNull();
});

it('404s on another person folder', function () {
    $other = User::factory()->admin()->create();
    $theirs = ConversationFolder::create([
        'user_id' => $other->id,
        'surface' => AssistantMessage::SURFACE_ADMIN,
        'name' => 'Suyas',
    ]);

    $this->actingAs($this->admin)
        ->patchJson(route('admin.conversations.folders.update', $theirs), ['name' => 'Mías'])
        ->assertNotFound();
});

it('refuses to file a conversation into somebody else folder', function () {
    $other = User::factory()->admin()->create();
    $theirs = ConversationFolder::create([
        'user_id' => $other->id,
        'surface' => AssistantMessage::SURFACE_ADMIN,
        'name' => 'Suyas',
    ]);

    $mine = conversationFor($this->admin, AssistantMessage::SURFACE_ADMIN);

    $this->actingAs($this->admin)
        ->patchJson(route('admin.conversations.update', $mine), ['folder' => $theirs->id])
        ->assertNotFound();
});

// ----------------------------------------------------------------- the turns

it('returns the whole transcript, summary included', function () {
    $conversation = conversationFor($this->admin, AssistantMessage::SURFACE_ADMIN);
    $conversation->forceFill(['summary' => 'Lo de antes.'])->save();

    foreach (['user' => 'Hola', 'assistant' => 'Buenas'] as $role => $body) {
        AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->admin->id,
            'surface' => AssistantMessage::SURFACE_ADMIN,
            'role' => $role,
            'body' => $body,
        ]);
    }

    $this->actingAs($this->admin)
        ->getJson(route('admin.conversations.show', $conversation))
        ->assertOk()
        ->assertJsonPath('summary', 'Lo de antes.')
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.body', 'Hola');
});

/** The meter's edit box: the one correction to the one thing she writes alone. */
it('lets the summary be rewritten by hand', function () {
    $conversation = conversationFor($this->admin, AssistantMessage::SURFACE_ADMIN);
    $conversation->forceFill(['summary' => 'Lo que ella entendió.'])->save();

    $this->actingAs($this->admin)
        ->patchJson(route('admin.conversations.summary', $conversation), [
            'summary' => 'Lo que de verdad pasó.',
        ])
        ->assertOk()
        ->assertJsonPath('summary', 'Lo que de verdad pasó.');
});
