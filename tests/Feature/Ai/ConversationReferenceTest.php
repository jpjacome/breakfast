<?php

declare(strict_types=1);

use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Ai\ConversationReferences;

/**
 * Item 5 — pointing at another conversation with #conv-N.
 *
 * ⚠️ MOST OF THIS IS THE OWNERSHIP CHECK, because a reference is a NUMBER a
 * person types. Without it, pasting `#conv-7` would be a way to read somebody
 * else's thread — and the failure would be invisible, since the answer would
 * simply be unusually well informed.
 */
beforeEach(function () {
    $this->references = app(ConversationReferences::class);
    $this->user = User::factory()->admin()->create();
});

/** A conversation with something in it worth quoting. */
function referenced(User $user, string $surface, ?Client $client = null, string $said = 'Quedamos en el claim corto.'): Conversation
{
    $conversation = Conversation::create([
        'user_id' => $user->id,
        'surface' => $surface,
        'client_id' => $client?->id,
        'title' => 'La del claim',
    ]);

    AssistantMessage::create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'surface' => $surface,
        'client_id' => $client?->id,
        'role' => 'assistant',
        'body' => $said,
    ]);

    return $conversation;
}

it('gathers nothing when nothing was referenced', function () {
    expect($this->references->digest(
        $this->user,
        AssistantMessage::SURFACE_ADMIN,
        null,
        '¿Cómo va todo?',
    ))->toBeNull();
});

/**
 * ⚠️ THE SUMMARY **AND** THE TAIL. The summary alone was the obvious design and
 * is empty on most conversations, because the summariser only fires at the
 * character budget — so a reference would usually carry nothing at all.
 */
it('gathers the last turns when there is no summary yet', function () {
    $other = referenced($this->user, AssistantMessage::SURFACE_ADMIN);

    $digest = $this->references->digest(
        $this->user,
        AssistantMessage::SURFACE_ADMIN,
        null,
        "Retoma lo de #conv-{$other->id}",
    );

    expect($digest)->toContain('Quedamos en el claim corto.')
        ->and($digest)->toContain('La del claim');
});

it('gathers the summary as well when there is one', function () {
    $other = referenced($this->user, AssistantMessage::SURFACE_ADMIN);
    $other->forceFill(['summary' => 'Se descartó el claim largo.'])->save();

    $digest = $this->references->digest(
        $this->user,
        AssistantMessage::SURFACE_ADMIN,
        null,
        "Retoma lo de #conv-{$other->id}",
    );

    expect($digest)->toContain('Se descartó el claim largo.')
        ->and($digest)->toContain('Quedamos en el claim corto.');
});

/** It is somebody's record of a conversation, never a statement about the brand. */
it('says plainly that a referenced conversation is not brand data', function () {
    $other = referenced($this->user, AssistantMessage::SURFACE_ADMIN);

    expect($this->references->digest(
        $this->user,
        AssistantMessage::SURFACE_ADMIN,
        null,
        "Mira #conv-{$other->id}",
    ))->toContain('no lo que la marca ES');
});

// ------------------------------------------------------------- the scoping

it('silently drops a reference to somebody else conversation', function () {
    $stranger = User::factory()->admin()->create();
    $theirs = referenced($stranger, AssistantMessage::SURFACE_ADMIN, said: 'Un secreto ajeno.');

    $digest = $this->references->digest(
        $this->user,
        AssistantMessage::SURFACE_ADMIN,
        null,
        "Mira #conv-{$theirs->id}",
    );

    // Null, not "no encontré esa conversación": a refusal answers the very
    // question the check exists to withhold.
    expect($digest)->toBeNull();
});

it('silently drops a reference across surfaces', function () {
    $dashboard = referenced($this->user, AssistantMessage::SURFACE_ADMIN);

    expect($this->references->digest(
        $this->user,
        AssistantMessage::SURFACE_PORTAL,
        1,
        "Mira #conv-{$dashboard->id}",
    ))->toBeNull();
});

/** ⚠️ ACC-01: one account, many brands, and a thread belongs to one of them. */
it('silently drops a reference to another of your own brands', function () {
    $one = Client::factory()->create();
    $two = Client::factory()->create();

    $user = User::factory()->create();
    $user->brands()->attach($one, ['role' => 'owner']);
    $user->brands()->attach($two, ['role' => 'owner']);

    $inTwo = referenced($user, AssistantMessage::SURFACE_PORTAL, $two);

    expect($this->references->digest(
        $user,
        AssistantMessage::SURFACE_PORTAL,
        $one->id,
        "Mira #conv-{$inTwo->id}",
    ))->toBeNull();
});

// --------------------------------------------------------------- the limits

/**
 * ⚠️ EACH REFERENCE IS PAID FOR ON THE TURN IT APPEARS IN. A question naming
 * ten conversations would quietly cost ten times what it looks like.
 */
it('reads at most three referenced conversations', function () {
    $made = collect(range(1, 5))->map(fn () => referenced($this->user, AssistantMessage::SURFACE_ADMIN));

    $question = $made->map(fn (Conversation $c): string => "#conv-{$c->id}")->implode(' ');

    $digest = $this->references->digest(
        $this->user,
        AssistantMessage::SURFACE_ADMIN,
        null,
        $question,
    );

    expect(substr_count($digest, '--- #conv-'))->toBe(3);
});

it('ignores a reference to the conversation you are already in', function () {
    $current = referenced($this->user, AssistantMessage::SURFACE_ADMIN);

    // Everything in it is already being replayed; saying it twice in one prompt
    // is paid-for repetition.
    expect($this->references->digest(
        $this->user,
        AssistantMessage::SURFACE_ADMIN,
        null,
        "¿Y lo de #conv-{$current->id}?",
        $current,
    ))->toBeNull();
});

/**
 * ⚠️ A BARE "#12" MUST NOT TRIGGER THIS. People write numbers for other reasons
 * — an invoice, a version, a street — and a turn that silently grew four
 * thousand characters because somebody mentioned one would be baffling.
 */
it('only answers to the #conv- form', function () {
    $other = referenced($this->user, AssistantMessage::SURFACE_ADMIN);

    expect($this->references->digest(
        $this->user,
        AssistantMessage::SURFACE_ADMIN,
        null,
        "La factura #{$other->id} está pendiente",
    ))->toBeNull();
});
