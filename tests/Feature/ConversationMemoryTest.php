<?php

declare(strict_types=1);

use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationFolder;
use App\Models\User;
use App\Services\Ai\ConversationBudget;
use App\Services\Ai\SummarisesConversations;
use Illuminate\Support\Facades\Http;

/**
 * Item 5 — a conversation has a beginning, a size, and a memory.
 *
 * ⚠️ WHAT THIS REPLACES was "the last 20 turns". Past that the model silently
 * stopped knowing the beginning: nothing said, nothing kept, and no way for the
 * person to tell. Here the early part is folded into a summary and everything
 * since is replayed verbatim — nothing is forgotten, it is carried differently.
 */
beforeEach(function () {
    $this->user = User::factory()->admin()->create();

    $this->conversation = Conversation::create([
        'user_id' => $this->user->id,
        'surface' => AssistantMessage::SURFACE_ADMIN,
    ]);

    config()->set('ai.conversation.budget_chars', 1000);
});

/** Add one exchange of a given size. */
function exchange(Conversation $conversation, int $chars = 100, string $question = 'Pregunta'): void
{
    foreach (['user' => $question, 'assistant' => str_repeat('a', $chars)] as $role => $body) {
        AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'surface' => $conversation->surface,
            'role' => $role,
            'body' => $body,
        ]);
    }
}

/** One summary back from the provider. */
function fakeSummary(string $text = 'Decidieron el claim «Caliente a las seis».'): void
{
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
    ])]);
}

it('fills the meter as the conversation grows', function () {
    expect(app(ConversationBudget::class)->percent($this->conversation))->toBe(0);

    exchange($this->conversation, 400);

    // Counted instantly, with no request having been made — which is the whole
    // reason this is characters and not tokens.
    expect(app(ConversationBudget::class)->percent($this->conversation->fresh()))
        ->toBeGreaterThan(30);
});

it('warns before it acts', function () {
    exchange($this->conversation, 850);

    $budget = app(ConversationBudget::class);

    expect($budget->shouldWarn($this->conversation->fresh()))->toBeTrue()
        // Warning is not doing. A summary that arrived unannounced mid-thought
        // would be the surprise this exists to avoid.
        ->and($budget->shouldSummarise($this->conversation->fresh()))->toBeFalse();
});

it('keeps the last four exchanges verbatim', function () {
    /*
     * ⚠️ THE ONE THAT PROTECTS THE BRIEF. §3 asks Brandy to hold references
     * like "une la 1 y la 3" and "hazla más corta" — every one of those points
     * at the turns immediately before it. A summary that swallowed them would
     * break the exact behaviour item 5 exists to deliver.
     */
    for ($i = 1; $i <= 10; $i++) {
        exchange($this->conversation, 200, "Pregunta {$i}");
    }

    $foldable = app(ConversationBudget::class)->foldable($this->conversation->fresh());

    expect($foldable['messages'])->toHaveCount(20 - ConversationBudget::KEEP_EXCHANGES * 2)
        // The last four exchanges are NOT in what gets folded.
        ->and($foldable['messages']->pluck('body')->contains('Pregunta 10'))->toBeFalse()
        ->and($foldable['messages']->pluck('body')->contains('Pregunta 1'))->toBeTrue();
});

it('folds the old part and replays only what came after', function () {
    fakeSummary();

    for ($i = 1; $i <= 10; $i++) {
        exchange($this->conversation, 200, "Pregunta {$i}");
    }

    expect(app(SummarisesConversations::class)->handle($this->conversation->fresh()))->toBeTrue();

    $conversation = $this->conversation->fresh();

    expect($conversation->summary)->toContain('Caliente a las seis')
        ->and($conversation->summarised_through_id)->not->toBeNull()
        // Nothing was deleted — the turns are all still there.
        ->and($conversation->messages()->count())->toBe(20)
        // But only the tail is replayed.
        ->and($conversation->replayable()->count())->toBe(ConversationBudget::KEEP_EXCHANGES * 2);
});

it('counts the summary against the budget, rather than resetting to zero', function () {
    // A meter that ignored the summary would drop to 0% and then climb from a
    // floor it refuses to admit to.
    $this->conversation->forceFill(['summary' => str_repeat('s', 500)])->save();

    expect(app(ConversationBudget::class)->used($this->conversation->fresh()))->toBe(500);
});

it('does not fold a conversation of three enormous messages', function () {
    // Over budget, nothing old enough to lose. Folding would replace the only
    // three things said with a paraphrase of them.
    exchange($this->conversation, 2000);

    $budget = app(ConversationBudget::class);

    expect($budget->used($this->conversation->fresh()))->toBeGreaterThan($budget->limit())
        ->and($budget->shouldSummarise($this->conversation->fresh()))->toBeFalse();
});

it('carries the previous summary into the next one', function () {
    /*
     * ⚠️ A SECOND FOLD THAT IGNORED THE FIRST WOULD DISCARD THE BEGINNING OF
     * THE CONVERSATION. By then the summary IS the early part, and it is the
     * only copy anybody has.
     */
    $this->conversation->forceFill([
        'summary' => 'Antes decidieron el nombre.',
        'summarised_through_id' => 0,
    ])->save();

    for ($i = 1; $i <= 10; $i++) {
        exchange($this->conversation, 200, "Pregunta {$i}");
    }

    fakeSummary();
    app(SummarisesConversations::class)->handle($this->conversation->fresh());

    Http::assertSent(function ($request) {
        $body = json_encode($request->data());

        // The old summary went in as material to carry forward.
        return str_contains($body, 'Antes decidieron el nombre');
    });
});

it('never throws when the provider fails', function () {
    // It runs after an answer already landed and was paid for. A hiccup must
    // not turn a good turn into a 500.
    Http::fake(['*' => Http::response('nope', 500)]);

    for ($i = 1; $i <= 10; $i++) {
        exchange($this->conversation, 200);
    }

    expect(app(SummarisesConversations::class)->handle($this->conversation->fresh()))
        ->toBeFalse()
        ->and($this->conversation->fresh()->summary)->toBeNull();
});

it('titles itself from the first question and never renames', function () {
    $this->conversation->titleFrom('¿Qué colores tiene la marca?');
    $this->conversation->titleFrom('Otra cosa completamente distinta');

    expect($this->conversation->fresh()->title)->toBe('¿Qué colores tiene la marca?');
});

it('keeps one person out of another person\'s history', function () {
    Conversation::create([
        'user_id' => User::factory()->admin()->create()->id,
        'surface' => AssistantMessage::SURFACE_ADMIN,
        'title' => 'Ajena',
    ]);

    $mine = Conversation::for($this->user->id, AssistantMessage::SURFACE_ADMIN)->get();

    expect($mine)->toHaveCount(1)
        ->and($mine->first()->id)->toBe($this->conversation->id);
});

it('scopes the portal list by brand and leaves the dashboard unscoped', function () {
    /*
     * ⚠️ Brandy's thread is per brand, so switching brands must switch the
     * list. The dashboard assistant crosses brands by design and must not be
     * filtered, or half somebody's own history disappears.
     */
    $brand = Client::factory()->create();
    $other = Client::factory()->create();

    foreach ([$brand, $other] as $client) {
        Conversation::create([
            'user_id' => $this->user->id,
            'surface' => AssistantMessage::SURFACE_PORTAL,
            'client_id' => $client->id,
        ]);
    }

    expect(Conversation::for($this->user->id, AssistantMessage::SURFACE_PORTAL, $brand->id)->count())
        ->toBe(1)
        ->and(Conversation::for($this->user->id, AssistantMessage::SURFACE_PORTAL)->count())
        ->toBe(2);
});

it('does not delete conversations when a folder is deleted', function () {
    /*
     * ⚠️ The most expensive mistake this panel could allow, and it sits one
     * click from an ordinary one. They fall back to the unfiled list.
     */
    $folder = ConversationFolder::create([
        'user_id' => $this->user->id,
        'surface' => AssistantMessage::SURFACE_ADMIN,
        'name' => 'Aurora',
    ]);

    $this->conversation->forceFill(['folder_id' => $folder->id])->save();

    $folder->delete();

    expect($this->conversation->fresh())->not->toBeNull()
        ->and($this->conversation->fresh()->folder_id)->toBeNull();
});

it('takes an archived conversation out of the list without losing it', function () {
    $this->conversation->forceFill(['archived_at' => now()])->save();

    expect(Conversation::for($this->user->id, AssistantMessage::SURFACE_ADMIN)->count())->toBe(0)
        ->and(Conversation::archived($this->user->id, AssistantMessage::SURFACE_ADMIN)->count())->toBe(1);
});
