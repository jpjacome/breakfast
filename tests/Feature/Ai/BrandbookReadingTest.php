<?php

declare(strict_types=1);

use App\Enums\DeliverableItem;
use App\Models\Client;
use App\Models\User;
use App\Services\Ai\Brand\DeliverableExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

/**
 * An upload is two phases: read the files once, then propose in slices.
 *
 * ⚠️ WHY IT IS SPLIT AT ALL. Reading a brandbook and writing forty-eight
 * proposals in one generation runs 60–150 seconds. Measured on the live host on
 * 2026-08-18: a request is killed outright at ~180s, and while long requests
 * are in flight the front end refuses everything behind them — the PUBLIC SITE
 * included — with a small-body 503 that Laravel never sees and never logs. The
 * team's screen said "no se pudo contactar al asistente" and the log was empty,
 * which is how it went undiagnosed.
 *
 * So the file is read once into a digest kept on the brand, and the proposals
 * are four short calls over that text. What is pinned here is the split itself,
 * because the failure it prevents is invisible from inside the app.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->client = Client::factory()->create(['name' => 'Cafetería Norte']);

    config()->set('ai.providers.deepseek.api_key', 'sk-test');
});

/** The provider speaks JSON, so a faked completion has to carry a document. */
function fakeTurn(array $payload): void
{
    Http::fake(['api.deepseek.com/*' => Http::response([
        'model' => 'deepseek-v4-pro',
        'choices' => [['message' => ['content' => json_encode($payload)], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 40],
    ])]);
}

function aBrandbook(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('brandbook.pdf', '%PDF-1.4 fake');
}

/* --- phase one: read ----------------------------------------------------- */

test('what the material looks like is kept too, and labelled as a description', function () {
    // The files do not travel twice, so a look that is not written down here
    // cannot be asked about later — "¿cómo se ve el toolkit?" a week on would
    // have nothing to answer from but a filename.
    fakeTurn([
        'reply' => 'Leí el brandbook.',
        'digest' => "## Colores\nMostaza #ECBB12.",
        'visual' => 'Páginas negras, un bloque de texto centrado, mucho aire.',
    ]);

    actingAs($this->admin)
        ->post(route('admin.clients.process.assistant', $this->client), [
            'files' => [aBrandbook()],
        ])
        ->assertOk();

    $digest = $this->client->fresh()->document_digest;

    expect($digest)
        ->toContain('Páginas negras')
        // ⚠️ Under its own subheading, and the wording is the point. A
        // description of a page read back later as a DEFINITION of the brand is
        // exactly what "leer no es reconocer" exists to prevent, and the label
        // is what keeps the two apart once the images are long gone.
        ->toContain('### Cómo se ve (descripción, no definición)')
        // Inside the file's own section, so re-reading that file replaces the
        // look along with the rest rather than leaving it orphaned.
        ->toContain('brandbook.pdf');
});

test('a page of pure graphics is kept on its look alone', function () {
    // ⚠️ THE CASE THIS FEATURE EXISTS FOR, and the one that was silently
    // dropped: a Look and Feel spread has no text to quote, so the digest comes
    // back empty and the description is the only thing the file had to give.
    // isEmpty() asked the digest alone, so nothing was stored at all. Found by
    // sending a real image to the real provider.
    fakeTurn([
        'reply' => 'Es una página gráfica, sin texto.',
        'digest' => '',
        'visual' => 'Fondo negro y un único bloque amarillo arriba al centro.',
    ]);

    actingAs($this->admin)
        ->post(route('admin.clients.process.assistant', $this->client), [
            'files' => [aBrandbook()],
        ])
        ->assertOk();

    expect($this->client->fresh()->document_digest)
        ->toContain('Fondo negro')
        ->toContain('brandbook.pdf')
        // No blank gap under the heading pretending text was cut out.
        ->not->toContain("\n\n\n");
});

test('a reading with no visual half is stored without an empty heading', function () {
    // Text-only material and audio return "". A subheading with nothing under
    // it would read as "we looked and there was nothing to see".
    fakeTurn([
        'reply' => 'Leí las notas.',
        'digest' => 'Habla de tú, nunca de usted.',
        'visual' => '',
    ]);

    actingAs($this->admin)
        ->post(route('admin.clients.process.assistant', $this->client), [
            'files' => [aBrandbook()],
        ])
        ->assertOk();

    expect($this->client->fresh()->document_digest)
        ->toContain('Habla de tú')
        ->not->toContain('Cómo se ve');
});

test('re-reading a file replaces its look as well as its text', function () {
    // ⚠️ A REAL heading. digestWithout() finds a section's filenames by
    // splitting on " — leído el ", so a fixture that words it any other way is
    // not matched and the test passes or fails for the wrong reason.
    $this->client->update([
        'document_digest' => "## brandbook.pdf — leído el 1 de agosto de 2026\n"
            ."Viejo.\n\n### Cómo se ve (descripción, no definición)\n\nPáginas blancas.",
    ]);

    fakeTurn([
        'reply' => 'Lo volví a leer.',
        'digest' => 'Nuevo.',
        'visual' => 'Páginas negras.',
    ]);

    actingAs($this->admin)
        ->post(route('admin.clients.process.assistant', $this->client), [
            'files' => [aBrandbook()],
        ])
        ->assertOk();

    // The whole section goes, look included — otherwise the brand would carry
    // two contradictory descriptions of the same document forever.
    expect($this->client->fresh()->document_digest)
        ->toContain('Páginas negras')
        ->not->toContain('Páginas blancas')
        ->not->toContain('Viejo');
});

test('an upload is read and kept, and proposes nothing yet', function () {
    fakeTurn([
        'reply' => 'Leí el brandbook.',
        'digest' => "## Colores\nMostaza #ECBB12.\n\n## Relato\nNació en Guadalajara.",
        'questions' => ['¿Tienen tipografía definida?'],
        'brand' => [],
    ]);

    $response = actingAs($this->admin)
        ->post(route('admin.clients.process.assistant', $this->client), [
            'files' => [aBrandbook()],
        ])
        ->assertOk();

    // The reading is kept, because the file does not travel again.
    expect($this->client->fresh()->document_digest)
        ->toContain('Mostaza #ECBB12')
        ->toContain('brandbook.pdf');

    // And no proposals in this phase: generating those is the part that was
    // taking the request past what the host allows.
    $response->assertJson(['proposals' => []]);

    expect($response->json('batches'))->toBe(DeliverableExtractor::batchCount());
});

test('files that say nothing readable ask for no batches', function () {
    fakeTurn(['reply' => 'No pude leer nada de ese archivo.', 'digest' => '']);

    actingAs($this->admin)
        ->post(route('admin.clients.process.assistant', $this->client), [
            'files' => [aBrandbook()],
        ])
        ->assertOk()
        // Zero, not four: four calls that each have nothing to read from is
        // four paid requests to be told nothing, twice over.
        ->assertJson(['batches' => 0]);

    expect($this->client->fresh()->document_digest)->toBeNull();
});

test('a second document is added to the reading, not swapped for it', function () {
    $this->client->update(['document_digest' => "## brandbook.pdf — leído antes\nMostaza #ECBB12."]);

    fakeTurn(['reply' => 'Leí el manual de tono.', 'digest' => 'Habla de tú, nunca de usted.']);

    actingAs($this->admin)->post(route('admin.clients.process.assistant', $this->client), [
        'files' => [aBrandbook()],
    ])->assertOk();

    // A brand's material arrives over weeks and the second document does not
    // supersede the first.
    expect($this->client->fresh()->document_digest)
        ->toContain('Mostaza #ECBB12')
        ->toContain('Habla de tú');
});

/* --- phase two: propose in slices ---------------------------------------- */

test('a batch call proposes only its own slice of the board', function () {
    $this->client->update(['document_digest' => 'Mostaza #ECBB12 es el color de la marca.']);

    fakeTurn([
        'reply' => '',
        'proposals' => [
            ['entregable' => DeliverableItem::Colores->value, 'value' => 'Mostaza #ECBB12', 'confidence' => 0.9],
        ],
    ]);

    actingAs($this->admin)
        ->postJson(route('admin.clients.process.assistant', $this->client), ['batch' => 1])
        ->assertOk()
        ->assertJson(['batch' => 1]);

    // The slice reaches the model, or all four calls would do the whole board.
    Http::assertSent(function ($request) {
        $said = json_encode($request->data()['messages'], JSON_UNESCAPED_UNICODE);

        return str_contains($said, 'SÓLO TE TOCAN ESTOS ENTREGABLES')
            && str_contains($said, DeliverableItem::cases()[DeliverableExtractor::BATCH_SIZE]->value);
    });
});

test('a batch call needs no message, unlike every other turn', function () {
    // "Escribe algo o adjunta un archivo" is right for a person and wrong for
    // the browser asking for slice three of a reading that already happened.
    $this->client->update(['document_digest' => 'Algo leído.']);

    fakeTurn(['reply' => '', 'proposals' => []]);

    actingAs($this->admin)
        ->postJson(route('admin.clients.process.assistant', $this->client), ['batch' => 0])
        ->assertOk();
});

test('a batch asked for before anything was read costs no API call', function () {
    Http::fake();

    actingAs($this->admin)
        ->postJson(route('admin.clients.process.assistant', $this->client), ['batch' => 0])
        ->assertOk()
        ->assertJson(['proposals' => []]);

    Http::assertNothingSent();
});

test('a batch past the end of the board is refused before it costs anything', function () {
    $this->client->update(['document_digest' => 'Algo leído.']);
    Http::fake();

    // The browser is told how many batches there are, so this only happens to
    // a hand-made request. Validation caps the index, so it is a 422 rather
    // than a paid call asking the model about entregables that do not exist.
    actingAs($this->admin)
        ->postJson(route('admin.clients.process.assistant', $this->client), [
            'batch' => DeliverableExtractor::batchCount(),
        ])
        ->assertStatus(422);

    Http::assertNothingSent();
});

/* --- the reading outlives the turn that produced it ---------------------- */

test('a typed question afterwards is answered from the document, not from its filename', function () {
    // The gap this closes: attachments ride the turn that carried them and
    // brand_onboarding_messages.attachments keeps names, never bytes — so the
    // model read a brandbook once and answered everything after it from
    // nothing but "brandbook.pdf".
    $this->client->update(['document_digest' => 'El claim es «Café de verdad».']);

    fakeTurn(['reply' => 'El claim es «Café de verdad».', 'proposals' => []]);

    actingAs($this->admin)->postJson(route('admin.clients.process.assistant', $this->client), [
        'message' => '¿Cuál era el claim?',
    ])->assertOk();

    Http::assertSent(function ($request) {
        return str_contains(
            json_encode($request->data()['messages'], JSON_UNESCAPED_UNICODE),
            'Café de verdad',
        );
    });
});

/* --- the cached prefix, which all of this had to leave alone ------------- */

test('the read and the batches share one byte-identical prefix', function () {
    // ⚠️ Blocks 1 and 2 are the same for every brand, every turn, forever. The
    // digest and the slice go in the VOLATILE turn for exactly this reason: a
    // per-brand system block, or a schema sliced per batch, would give each of
    // the five calls its own prefix and turn one cached read into five paid
    // ones — a fix for a timeout that quintupled the bill instead.
    $this->client->update(['document_digest' => 'Algo leído.']);

    fakeTurn(['reply' => 'ok', 'digest' => 'x', 'proposals' => []]);

    actingAs($this->admin)->post(route('admin.clients.process.assistant', $this->client), [
        'files' => [aBrandbook()],
    ]);

    actingAs($this->admin)->postJson(route('admin.clients.process.assistant', $this->client), [
        'batch' => 2,
    ]);

    $prefixes = [];

    Http::assertSent(function ($request) use (&$prefixes) {
        $messages = $request->data()['messages'];
        // The two system blocks, before anything volatile.
        $prefixes[] = json_encode(array_slice($messages, 0, 2));

        return true;
    });

    expect($prefixes)->toHaveCount(2)
        ->and($prefixes[0])->toBe($prefixes[1]);
});
