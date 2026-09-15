<?php

declare(strict_types=1);

use App\Enums\DeliverableItem;
use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| A conversation belongs to the person having it
|--------------------------------------------------------------------------
| Each user gets their own running thread, replayed as context on their next
| question. The rule that matters most is the one about who CANNOT read it:
| an admin must not be able to reach a client's conversation, and two people
| in the same brand must not share one either.
*/

beforeEach(function () {
    config()->set('ai.providers.deepseek.api_key', 'sk-test');

    $this->admin = User::factory()->admin()->create();
    $this->brand = Client::factory()->create(['name' => 'Alea', 'slug' => 'alea']);
    $this->brand->deliverables->update([DeliverableItem::Relato->value => 'Nació en Guayaquil.']);
});

function fakeReply(string $text = 'Listo.'): void
{
    Http::fake(['api.deepseek.com/*' => Http::response([
        'model' => 'deepseek-v4-pro',
        'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 30],
    ])]);
}

/* --- the admin's thread --------------------------------------------------- */

test('an admin question and its answer are both kept', function () {
    fakeReply('Ninguna está trabada.');

    actingAs($this->admin)
        ->postJson(route('admin.assistant'), ['question' => '¿Qué marcas están trabadas?'])
        ->assertOk();

    $thread = AssistantMessage::where('user_id', $this->admin->id)->orderBy('id')->get();

    expect($thread)->toHaveCount(2)
        ->and($thread[0]->role)->toBe('user')
        ->and($thread[0]->body)->toBe('¿Qué marcas están trabadas?')
        ->and($thread[1]->role)->toBe('assistant')
        ->and($thread[1]->body)->toBe('Ninguna está trabada.')
        ->and($thread[0]->surface)->toBe(AssistantMessage::SURFACE_ADMIN);
});

test('the next question carries the earlier turns', function () {
    fakeReply();

    actingAs($this->admin)->postJson(route('admin.assistant'), ['question' => '¿Cómo va Alea?'])->assertOk();
    actingAs($this->admin)->postJson(route('admin.assistant'), ['question' => '¿y comparada con las demás?'])->assertOk();

    // "¿y comparada con las demás?" means nothing without the turn before it.
    Http::assertSent(function ($request) {
        $bodies = array_column($request['messages'], 'content');

        return in_array('¿Cómo va Alea?', $bodies, true);
    });
});

test('a failed call leaves no half-turn behind', function () {
    Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'nope']], 500)]);

    actingAs($this->admin)
        ->postJson(route('admin.assistant'), ['question' => '¿Cómo va todo?'])
        ->assertStatus(502);

    // A question with no answer, replayed into the next request, reads as the
    // assistant having ignored somebody.
    expect(AssistantMessage::count())->toBe(0);
});

/* --- who cannot read it --------------------------------------------------- */

test('one admin does not inherit another admin conversation', function () {
    fakeReply();

    $other = User::factory()->admin()->create();

    actingAs($other)->postJson(route('admin.assistant'), ['question' => 'Secreto de la otra cuenta'])->assertOk();
    actingAs($this->admin)->postJson(route('admin.assistant'), ['question' => '¿Cómo va Alea?'])->assertOk();

    Http::assertSent(function ($request) {
        return ! in_array('Secreto de la otra cuenta', array_column($request['messages'], 'content'), true);
    });
});

test('an admin never sees a client conversation', function () {
    fakeReply();

    $owner = User::factory()->clientOwner($this->brand->id)->create();

    actingAs($owner)->postJson(route('portal.assistant'), ['question' => 'Algo privado de la marca'])->assertOk();
    actingAs($this->admin)->postJson(route('admin.assistant'), ['question' => '¿Qué me preguntaron?'])->assertOk();

    // The whole point of keying the thread on the user: there is no query in
    // the app that would fetch somebody else's.
    Http::assertSent(function ($request) {
        return ! in_array('Algo privado de la marca', array_column($request['messages'], 'content'), true);
    });
});

test('two people in the same brand do not share a thread', function () {
    fakeReply();

    $owner = User::factory()->clientOwner($this->brand->id)->create();
    $member = User::factory()->clientMember($this->brand->id)->create();

    actingAs($owner)->postJson(route('portal.assistant'), ['question' => 'Lo que preguntó la dueña'])->assertOk();
    actingAs($member)->postJson(route('portal.assistant'), ['question' => '¿Cuál es el relato?'])->assertOk();

    Http::assertSent(function ($request) {
        return ! in_array('Lo que preguntó la dueña', array_column($request['messages'], 'content'), true);
    });
});

test('the two surfaces keep separate threads for the same person', function () {
    fakeReply();

    // Only Breakfast staff can reach both, and an answer about the portfolio
    // has no business turning up in a brand's own conversation.
    actingAs($this->admin)->postJson(route('admin.assistant'), ['question' => 'Pregunta del panel'])->assertOk();

    expect(AssistantMessage::where('surface', AssistantMessage::SURFACE_PORTAL)->count())->toBe(0)
        ->and(AssistantMessage::where('surface', AssistantMessage::SURFACE_ADMIN)->count())->toBe(2);
});

/* --- the client's assistant ----------------------------------------------- */

test('a client asks their own brand assistant', function () {
    fakeReply('Tu relato dice que nació en Guayaquil.');

    $owner = User::factory()->clientOwner($this->brand->id)->create();

    actingAs($owner)
        ->postJson(route('portal.assistant'), ['question' => '¿Cuál es nuestro relato?'])
        ->assertOk()
        ->assertJson(['reply' => 'Tu relato dice que nació en Guayaquil.']);

    expect(AssistantMessage::where('user_id', $owner->id)
        ->where('surface', AssistantMessage::SURFACE_PORTAL)->count())->toBe(2);
});

test('a brand with nothing written says so instead of improvising', function () {
    Http::fake();

    $empty = Client::factory()->create();
    $owner = User::factory()->clientOwner($empty->id)->create();

    actingAs($owner)
        ->postJson(route('portal.assistant'), ['question' => '¿Cuál es nuestro relato?'])
        ->assertOk();

    // No context means no call at all — an empty context is exactly what the
    // model would fill in with something plausible.
    Http::assertNothingSent();
});

test('the client assistant refuses an empty question as JSON, not a redirect', function () {
    $owner = User::factory()->clientOwner($this->brand->id)->create();

    // A redirect here is a silent failure in the fetch() that sent it. Trap 13.
    actingAs($owner)
        ->postJson(route('portal.assistant'), ['question' => '  '])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['question']]);
});

test('a Breakfast user is not a brand and gets nothing here', function () {
    actingAs($this->admin)
        ->postJson(route('portal.assistant'), ['question' => '¿Cuál es el relato?'])
        ->assertNotFound();
});

test('the client dashboard carries the panel', function () {
    $owner = User::factory()->clientOwner($this->brand->id)->create();

    actingAs($owner)
        ->get(route('portal.home'))
        ->assertOk()
        ->assertSee('data-assistant', escape: false)
        ->assertSee(route('portal.assistant'), escape: false)
        // The brand picker is the admin's: this side has one brand and must
        // not offer a way to name another.
        ->assertDontSee('assistant-brand', escape: false);
});

/* --- the conversation is there when you come back ------------------------- */

test('the panel opens with the previous conversation already in it', function () {
    fakeReply('Alea va en el paso 1.');

    $owner = User::factory()->clientOwner($this->brand->id)->create();

    actingAs($owner)->postJson(route('portal.assistant'), ['question' => '¿En qué paso vamos?'])->assertOk();

    // A fresh page load, as if they had logged out and come back.
    actingAs($owner)
        ->get(route('portal.home'))
        ->assertOk()
        ->assertSee('¿En qué paso vamos?')
        ->assertSee('Alea va en el paso 1.');
});

test('the Breakfast dashboard opens with its own thread', function () {
    fakeReply('Ninguna está trabada.');

    actingAs($this->admin)->postJson(route('admin.assistant'), ['question' => '¿Qué está trabado?'])->assertOk();

    actingAs($this->admin)
        ->get(route('admin.home'))
        ->assertOk()
        ->assertSee('¿Qué está trabado?')
        ->assertSee('Ninguna está trabada.');
});

test('a rendered thread is only ever your own', function () {
    fakeReply();

    $owner = User::factory()->clientOwner($this->brand->id)->create();
    $member = User::factory()->clientMember($this->brand->id, [])->create();

    actingAs($owner)->postJson(route('portal.assistant'), ['question' => 'Lo que preguntó la dueña'])->assertOk();

    // Same brand, same screen, different person: the panel is keyed on the
    // user, so there is nothing of hers to render.
    actingAs($member)
        ->get(route('portal.home'))
        ->assertOk()
        ->assertDontSee('Lo que preguntó la dueña');
});

test('the two surfaces do not show each other conversations', function () {
    fakeReply();

    // Only Breakfast staff can reach both screens. What they asked the
    // dashboard has no business appearing in a brand's own panel.
    actingAs($this->admin)->postJson(route('admin.assistant'), ['question' => 'Pregunta del panel'])->assertOk();

    $owner = User::factory()->clientOwner($this->brand->id)->create();

    actingAs($owner)
        ->get(route('portal.home'))
        ->assertOk()
        ->assertDontSee('Pregunta del panel');
});

/* -------------------------------------------------------------------------
 | Sending an image or a voice note
 ------------------------------------------------------------------------- */

test('a client can send an image with a question', function () {
    fakeReply('Ese amarillo no es el de tu paleta.');

    $owner = User::factory()->clientOwner($this->brand->id)->create();

    actingAs($owner)
        ->post(route('portal.assistant'), [
            'question' => '¿Qué te parece este post?',
            'files' => [UploadedFile::fake()->image('captura.png')],
        ])
        ->assertOk();

    // The NAME is kept, never the bytes: the transcript has to read right on
    // reload without a screenshot living in a text column.
    $asked = AssistantMessage::where('user_id', $owner->id)->where('role', 'user')->sole();

    expect($asked->attachments)->toBe(['captura.png']);
});

test('a voice note is a question on its own', function () {
    fakeReply('Entendido.');

    $owner = User::factory()->clientOwner($this->brand->id)->create();

    // Requiring text alongside a recording defeats the point of recording it.
    actingAs($owner)
        ->post(route('portal.assistant'), [
            'files' => [UploadedFile::fake()->create('nota-de-voz.wav', 40, 'audio/wav')],
        ])
        ->assertOk();
});

test('a format the model cannot open is refused as a file problem, not a provider one', function () {
    $owner = User::factory()->clientOwner($this->brand->id)->create();

    // webm is exactly the case: browsers record it happily and the provider
    // 400s on it, which is why the recorder re-encodes to WAV.
    actingAs($owner)
        ->post(route('portal.assistant'), [
            'question' => '¿Y esto?',
            'files' => [UploadedFile::fake()->create('grabacion.webm', 40, 'audio/webm')],
        ])
        ->assertStatus(422);
});

test('more than two files in one message is refused', function () {
    $owner = User::factory()->clientOwner($this->brand->id)->create();

    actingAs($owner)
        ->post(route('portal.assistant'), [
            'question' => 'Mira',
            'files' => [
                UploadedFile::fake()->image('a.png'),
                UploadedFile::fake()->image('b.png'),
                UploadedFile::fake()->image('c.png'),
            ],
        ])
        ->assertStatus(422);
});

test('the Breakfast dashboard takes an image too', function () {
    fakeReply('Listo.');

    actingAs($this->admin)
        ->post(route('admin.assistant'), [
            'question' => '¿Qué opinas?',
            'files' => [UploadedFile::fake()->image('mockup.png')],
        ])
        ->assertOk();

    expect(AssistantMessage::where('user_id', $this->admin->id)->where('role', 'user')->sole()->attachments)
        ->toBe(['mockup.png']);
});

test('an earlier turn replays the file by name, never by re-sending it', function () {
    fakeReply();

    $owner = User::factory()->clientOwner($this->brand->id)->create();

    actingAs($owner)->post(route('portal.assistant'), [
        'question' => 'Mira esto',
        'files' => [UploadedFile::fake()->image('captura.png')],
    ])->assertOk();

    actingAs($owner)->postJson(route('portal.assistant'), ['question' => '¿y el otro color?'])->assertOk();

    // Re-inlining the image on every later question would multiply the cost of
    // a conversation by the size of its first screenshot.
    Http::assertSent(function ($request) {
        return in_array('Mira esto

[Adjuntó: captura.png]', array_column($request['messages'], 'content'), true);
    });
});
