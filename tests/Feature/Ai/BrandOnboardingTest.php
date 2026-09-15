<?php

use App\Actions\StartBrandDraft;
use App\Enums\ClientStatus;
use App\Enums\DeliverableItem;
use App\Models\Client;
use App\Models\User;
use App\Services\Ai\Brand\DeliverableSchema;
use App\Services\Ai\Brand\ProposalReconciler;
use App\Services\Ai\Data\Attachment;
use App\Services\Ai\Data\DeliverableProposal;
use App\Services\Ai\Data\Message;
use App\Services\Ai\Exceptions\InvalidRequest;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| The rules around the assistant, none of which need a provider
|--------------------------------------------------------------------------
| What is deliberately NOT tested here is the model's judgement — whether it
| reads a brandbook well. That needs credentials and an eval set. Everything
| below is the machinery around it, which is where the damage would be done if
| it were wrong.
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->client = Client::factory()->create(['name' => 'Cafetería Norte']);
});

/* --- nothing is ever applied without a click ------------------------------
   There is no confidence threshold and no auto-fill. Every proposal comes back
   as a card. That is the reason brand_deliverables has no provenance column:
   there is no state in which the model authored a value on its own.
   ------------------------------------------------------------------------- */

test('a proposal for an empty entregable is still a card', function () {
    $cards = (new ProposalReconciler)->reconcile($this->client->deliverables, [
        new DeliverableProposal(DeliverableItem::Relato, 'Nació en Guadalajara.', 0.95, 'Brandbook, p. 4'),
    ]);

    expect($cards)->toHaveCount(1)
        // Nothing to compare against, which the card renders differently.
        ->and($cards[0]['current'])->toBe('')
        ->and($cards[0]['value'])->toBe('Nació en Guadalajara.');
});

test('a proposal over written content carries what it would replace', function () {
    $this->client->deliverables->update([
        DeliverableItem::Relato->value => 'Lo que escribió el equipo.',
    ]);

    $cards = (new ProposalReconciler)->reconcile($this->client->deliverables->fresh(), [
        new DeliverableProposal(DeliverableItem::Relato, 'Lo que leyó el modelo.', 0.9, 'Brandbook, p. 4'),
    ]);

    expect($cards[0]['current'])->toBe('Lo que escribió el equipo.')
        ->and($cards[0]['value'])->toBe('Lo que leyó el modelo.');
});

test('unsaved form state wins over the saved row', function () {
    // Somebody typed two minutes ago and has not saved yet. Reading the
    // database here would report the entregable empty and lose their work.
    $cards = (new ProposalReconciler)->reconcile(
        $this->client->deliverables,
        [new DeliverableProposal(DeliverableItem::Relato, 'Del modelo.', 0.9, 'Brandbook')],
        [DeliverableItem::Relato->value => 'Escrito hace dos minutos.'],
    );

    expect($cards[0]['current'])->toBe('Escrito hace dos minutos.');
});

test('a proposal that says what is already written is dropped', function () {
    $this->client->deliverables->update([
        DeliverableItem::Relato->value => 'Nació  en   Guadalajara.',
    ]);

    $cards = (new ProposalReconciler)->reconcile($this->client->deliverables->fresh(), [
        new DeliverableProposal(DeliverableItem::Relato, 'nació en guadalajara.', 0.9, 'Brief'),
    ]);

    // Whitespace and case are not a disagreement worth reviewing; without this
    // a re-read of the same brandbook queues twenty identical cards.
    expect($cards)->toBe([]);
});

test('least certain readings are reviewed first', function () {
    $cards = (new ProposalReconciler)->reconcile($this->client->deliverables, [
        new DeliverableProposal(DeliverableItem::Colores, '#ECBB12 — acento', 0.95, 'Brandbook, p. 12'),
        new DeliverableProposal(DeliverableItem::Tono, 'Cercano pero directo.', 0.3, 'Nota de voz, 2:10'),
    ]);

    // Confidence decides nothing any more — it only sorts attention, so a
    // shaky reading is not at the bottom of a list of forty.
    expect(array_column($cards, 'entregable'))
        ->toBe([DeliverableItem::Tono->value, DeliverableItem::Colores->value]);
});

/* --- what the model sends back is untrusted -------------------------------- */

test('a proposal for an entregable that does not exist is discarded', function () {
    expect(DeliverableProposal::fromArray(['entregable' => 'inventado', 'value' => 'x']))->toBeNull()
        ->and(DeliverableProposal::fromArray(['entregable' => 'relato', 'value' => '  ']))->toBeNull()
        ->and(DeliverableProposal::fromArray('no es un array'))->toBeNull();
});

test('a model that says nothing about confidence gets the middle, not certainty', function () {
    $proposal = DeliverableProposal::fromArray(['entregable' => 'tono', 'value' => 'Cercano.']);

    expect($proposal->confidence)->toBe(0.5);
});

test('nonsense confidence is clamped into range', function () {
    expect(DeliverableProposal::fromArray(['entregable' => 'relato', 'value' => 'x', 'confidence' => 7])->confidence)
        ->toBe(1.0)
        ->and(DeliverableProposal::fromArray(['entregable' => 'relato', 'value' => 'x', 'confidence' => -3])->confidence)
        ->toBe(0.0);
});

/* --- the schema the model reads ------------------------------------------- */

test('the schema exports every entregable on the board, and only those', function () {
    $schema = new DeliverableSchema;

    expect($schema->toArray())->toHaveCount(48)
        ->and($schema->keys())->toBe(DeliverableItem::columns());
});

test('the prompt block names every entregable key', function () {
    $block = (new DeliverableSchema)->promptBlock();

    foreach (DeliverableItem::cases() as $item) {
        expect($block)->toContain($item->value);
    }
});

/* --- the screen the assistant lives on ------------------------------------ */

test('the assistant sits on the process screen, above the board', function () {
    $page = actingAs($this->admin)
        ->get(route('admin.clients.process.edit', $this->client))
        ->assertOk();

    $page->assertSee('data-brand-assistant', escape: false)
        ->assertSee('data-orb', escape: false)
        ->assertSee('data-process-form', escape: false)
        ->assertSee('Cafetería Norte');
});

test('creating a brand with nothing filled in still works', function () {
    actingAs($this->admin)->post(route('admin.clients.store'), [
        'name' => 'The Coffee Club',
        'status' => 'activo',
    ])->assertRedirect();

    $client = Client::where('name', 'The Coffee Club')->firstOrFail();

    expect($client->deliverables)->not->toBeNull()
        ->and($client->deliverables->filledCount())->toBe(0)
        // It stops being a draft the moment it is created.
        ->and($client->status->isDraft())->toBeFalse();
});

test('the new brand screen carries the assistant and the whole board', function () {
    $page = actingAs($this->admin)->get(route('admin.clients.create'))->assertOk();

    // It is the process screen before the brand exists: same assistant, same
    // 48 textareas, so a brandbook dropped here fills them the same way.
    $page->assertSee('Una marca nueva')
        ->assertSee('data-brand-assistant', escape: false)
        ->assertSee('entregables[relato]', escape: false)
        ->assertSee('entregables[colores]', escape: false);
});

/* --- attachments ---------------------------------------------------------- */

test('a file the model cannot open is refused before any api call', function () {
    expect(fn () => Attachment::make('hoja.xlsx', 'application/vnd.ms-excel', 'x'))
        ->toThrow(InvalidRequest::class);
});

test('audio goes as an audio part, not as a file', function () {
    // Getting this shape wrong is a 400 from the provider rather than a bad
    // answer, so it is worth pinning even without credentials to try it.
    $part = Attachment::make('nota.m4a', 'audio/x-m4a', 'binario')->toContentPart();

    expect($part['type'])->toBe('input_audio')
        ->and($part['input_audio']['format'])->toBe('m4a')
        // Raw base64, not a data URI: that is the one place audio differs.
        ->and($part['input_audio']['data'])->toBe(base64_encode('binario'));
});

test('every accepted audio mime maps to a format openrouter documents', function () {
    // wav, mp3, aiff, aac, ogg, flac, m4a, pcm16, pcm24 — verified 2026-08-12.
    // A value off this list is a 400 from the provider, so the mapping and the
    // accepted-mime list have to stay in step. webm is the trap: browsers
    // produce it, OpenRouter does not take it.
    $documented = ['wav', 'mp3', 'aiff', 'aac', 'ogg', 'flac', 'm4a', 'pcm16', 'pcm24'];

    foreach (Attachment::AUDIO_MIMES as $mime) {
        $format = Attachment::make('nota', $mime, 'x')->toContentPart()['input_audio']['format'];

        expect($documented)->toContain($format);
    }

    expect(Attachment::AUDIO_MIMES)->not->toContain('audio/webm');
});

test('the audio formats a phone actually produces are accepted', function () {
    // iPhone voice memos are m4a and WhatsApp notes are ogg. Refusing those two
    // would mean refusing almost every voice note this feature will ever see.
    foreach (['audio/x-m4a' => 'm4a', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3'] as $mime => $format) {
        $attachment = Attachment::make("nota.{$format}", $mime, 'x');

        expect($attachment->isAudio())->toBeTrue()
            ->and($attachment->toContentPart()['input_audio']['format'])->toBe($format);
    }
});

test('audio gets a bigger size limit than a document', function () {
    // Compared as constants rather than by building a file: the caps are now
    // 50MB and 100MB, and materialising one — then base64ing it, which adds a
    // third again — exhausts the test runner's memory before it proves
    // anything. Half an hour of a workshop recording is still one document.
    expect(Attachment::MAX_AUDIO_BYTES)->toBeGreaterThan(Attachment::MAX_BYTES);
});

test('the caps are the provider ceilings, not numbers we picked', function () {
    // There is deliberately no test that builds an oversize file and watches it
    // be refused: the caps are now 50MB and 100MB, and materialising one costs
    // more memory than the runner has, to prove a strlen comparison. The
    // browser-side guard in process-assistant.js is what a person actually
    // meets, and it reads these same numbers.
    // Gemini, verified 2026-08-13: PDFs cap at 50MB (inline or Files API),
    // everything else inline at 100MB. Raising MAX_BYTES past this would let a
    // big brandbook upload for a minute and then fail at the provider, which
    // is a worse answer than refusing it in the browser.
    expect(Attachment::MAX_BYTES)->toBe(50 * 1024 * 1024)
        ->and(Attachment::MAX_AUDIO_BYTES)->toBe(100 * 1024 * 1024);
});

test('a transcript names an audio attachment without carrying its bytes', function () {
    $message = Message::userWithAttachments('Escucha esto', [
        Attachment::make('nota.mp3', 'audio/mpeg', 'binario'),
    ]);

    expect($message->text())->toBe("Escucha esto\n[audio adjunto]");
});

/* --- caching --------------------------------------------------------------
   Gemini needs an explicit breakpoint where DeepSeek needs none. Without one,
   the 48-entregable schema is paid for in full on every turn of every
   conversation.
   ------------------------------------------------------------------------- */

test('a cacheable block carries a breakpoint when the provider needs one', function () {
    config([
        'ai.provider' => 'openrouter',
        'ai.providers.openrouter.cache_breakpoints' => true,
    ]);

    $content = Message::cacheableSystem('el esquema')->content;

    expect($content[0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($content[0]['text'])->toBe('el esquema');
});

test('a cacheable block stays a plain string where breakpoints are not used', function () {
    // DeepSeek caches on a matching prefix and has nothing to mark; sending it
    // a content-part array would change the prefix for no benefit.
    config([
        'ai.provider' => 'deepseek',
        'ai.providers.deepseek.cache_breakpoints' => false,
    ]);

    expect(Message::cacheableSystem('el esquema')->content)->toBe('el esquema');
});

test('a message with no attachments stays a plain string', function () {
    // The cacheable prefix depends on this: wrapping every text turn in parts
    // would change the bytes of requests that have nothing to do with files.
    expect(Message::userWithAttachments('Hola', [])->content)->toBe('Hola');
});

test('a message with an attachment carries the text first', function () {
    $message = Message::userWithAttachments('Lee esto', [
        Attachment::make('logo.png', 'image/png', 'binario'),
    ]);

    expect($message->content[0]['type'])->toBe('text')
        ->and($message->content[1]['type'])->toBe('image_url')
        ->and($message->text())->toContain('[imagen adjunta]');
});

/* --- /clientes/nueva: typing is content too --------------------------------
   Reported by a tester on 2026-08-14. Saying "empecemos una marca nueva, se
   llama Patito" got a fixed request for a brandbook, twice, because the screen
   only created a draft for an uploaded FILE or a filled form field — and
   without a draft it answered from a canned string without ever calling the
   model. The name the person had just given was read by nothing.
   ------------------------------------------------------------------------- */

/** The extractor speaks JSON, so the faked completion has to carry a document. */
function fakeExtraction(array $payload): void
{
    Http::fake(['api.deepseek.com/*' => Http::response([
        'model' => 'deepseek-v4-pro',
        'choices' => [['message' => ['content' => json_encode($payload)], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 40],
    ])]);
}

test('typing a brand name creates the draft and offers the name as a card', function () {
    config()->set('ai.providers.deepseek.api_key', 'sk-test');

    fakeExtraction([
        'reply' => 'Listo, la llamo Patito. ¿Tienes el brandbook?',
        'proposals' => [],
        'questions' => ['¿A qué se dedica Patito?'],
        'brand' => ['name' => 'Patito'],
    ]);

    $response = actingAs($this->admin)
        ->postJson(route('admin.clients.draft.assistant'), [
            'message' => 'empecemos una marca nueva, el nombre es Patito',
        ])
        ->assertOk();

    // The name comes back as a card, like every other reading — a person still
    // says yes to it, and clicking is what puts it in the field.
    expect($response->json('brand.name'))->toBe('Patito')
        ->and($response->json('draft'))->not->toBeNull();
});

test('the reading does not name the row before anybody accepts it', function () {
    config()->set('ai.providers.deepseek.api_key', 'sk-test');

    fakeExtraction([
        'reply' => 'Anotado.',
        'proposals' => [],
        'questions' => [],
        'brand' => ['name' => 'Patito'],
    ]);

    actingAs($this->admin)->postJson(route('admin.clients.draft.assistant'), [
        'message' => 'empecemos una marca nueva, el nombre es Patito',
    ])->assertOk();

    // Otherwise the brand list says Patito while the Nombre field on screen is
    // still empty, and the model has written to the database with nobody
    // having agreed. The card is the only way in; the autosave then renames
    // the row from the field.
    expect(Client::where('name', 'Patito')->exists())->toBeFalse()
        ->and(Client::where('status', ClientStatus::Borrador)->sole()->name)
        ->toBe(StartBrandDraft::PLACEHOLDER_NAME);
});

test('the reply is the model, not a canned sentence', function () {
    config()->set('ai.providers.deepseek.api_key', 'sk-test');

    fakeExtraction([
        'reply' => 'Anotado: Patito.',
        'proposals' => [],
        'questions' => [],
        'brand' => ['name' => 'Patito'],
    ]);

    actingAs($this->admin)
        ->postJson(route('admin.clients.draft.assistant'), ['message' => 'se llama Patito'])
        ->assertOk()
        ->assertJsonPath('reply', 'Anotado: Patito.');
});

test('saying hello answers without creating a brand', function () {
    config()->set('ai.providers.deepseek.api_key', 'sk-test');

    // Nothing to file: no name, no proposals. Abandoned drafts are a real
    // cost — they sit in the brand list — so the trigger stays content.
    fakeExtraction([
        'reply' => '¿Con qué marca empezamos?',
        'proposals' => [],
        'questions' => [],
        'brand' => [],
    ]);

    $before = Client::count();

    actingAs($this->admin)
        ->postJson(route('admin.clients.draft.assistant'), ['message' => 'hola'])
        ->assertOk()
        ->assertJsonPath('draft', null);

    expect(Client::count())->toBe($before);
});

test('an empty turn is refused before it costs a call', function () {
    // No message and no file is nothing to answer, and it never reaches the
    // controller. That is why there is no canned "sube un brandbook" reply any
    // more: every turn that gets through has something in it to read.
    // preventStrayRequests() in Pest.php turns any call here into a failure
    // rather than a bill.
    $before = Client::count();

    actingAs($this->admin)
        ->postJson(route('admin.clients.draft.assistant'), ['message' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('message');

    expect(Client::count())->toBe($before);
});
