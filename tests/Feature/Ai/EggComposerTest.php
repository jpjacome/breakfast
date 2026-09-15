<?php

declare(strict_types=1);

use App\Enums\BrandEggLayer;
use App\Enums\BrandEggState;
use App\Enums\DeliverableItem;
use App\Models\Client;
use App\Services\Ai\Exceptions\ProviderUnavailable;
use App\Services\BrandEgg\EggComposer;
use Illuminate\Support\Facades\Http;

/**
 * The composer: five layers out of the entregables that feed them.
 *
 * What is pinned here is mostly what must NOT happen. The Egg sits above the
 * 48 entregables in the assistant's context, so a layer that invents an
 * attribute does not mislead one conversation — it becomes the brand, and every
 * later answer is built on it.
 */
beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Cafetería Norte']);

    config()->set('ai.providers.deepseek.api_key', 'sk-test');
});

/** The composer asks for prose, not JSON — a faked completion is a paragraph. */
function fakeParagraph(string $text = 'Un párrafo sintetizado.'): void
{
    Http::fake(['api.deepseek.com/*' => Http::response([
        'model' => 'deepseek-v4-pro',
        'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 90],
    ])]);
}

/** Enough of the Esencia sources filled for that layer to be composable. */
function fillEsencia(Client $client): void
{
    $client->deliverables->update([
        DeliverableItem::Relato->value => 'Nació en una cocina de Guadalajara.',
        DeliverableItem::Valores->value => 'Cercanía, oficio, paciencia.',
    ]);
}

/* --- what feeds a layer -------------------------------------------------- */

test('a layer is sent exactly the entregables its enum names, and no others', function () {
    // The enum is the single source list. A screen, a route and a prompt
    // builder each carrying their own would drift, and the Egg would be
    // composed from one set of entregables and explained by another.
    $this->client->deliverables->update([
        DeliverableItem::Relato->value => 'Nació en una cocina de Guadalajara.',
        DeliverableItem::Valores->value => 'Cercanía, oficio, paciencia.',
        // Not a source of Personalidad. It must not travel in that call.
        DeliverableItem::Tono->value => 'SECRETO-NO-DEBE-VIAJAR',
        DeliverableItem::Arquetipos->value => 'El Cuidador.',
    ]);

    fakeParagraph();

    app(EggComposer::class)->compose($this->client->fresh(), BrandEggLayer::Personalidad);

    Http::assertSent(function ($request) {
        $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

        return str_contains($sent, 'El Cuidador')
            && str_contains($sent, 'Cercanía, oficio, paciencia')
            && ! str_contains($sent, 'SECRETO-NO-DEBE-VIAJAR');
    });
});

test('an entregable a layer reads but nobody wrote is named NO DEFINIDO, not omitted', function () {
    // Same rule as BrandDeliverables::toMarkdown(): silence about a source
    // gets completed with whatever is most probable for the category, while an
    // explicit absence does not.
    fillEsencia($this->client);
    fakeParagraph();

    app(EggComposer::class)->compose($this->client->fresh(), BrandEggLayer::Esencia);

    Http::assertSent(fn ($request) => str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'NO DEFINIDO'));
});

test('a layer whose sources are all empty is not composed, and costs nothing', function () {
    // An empty layer is honest; a layer of hedging is noise, and paying a
    // provider to write the hedging is worse. No call is made at all — which
    // Http::preventStrayRequests() would catch if one were.
    $composer = app(EggComposer::class);

    expect($composer->compose($this->client, BrandEggLayer::Esencia))->toBeNull()
        ->and($this->client->fresh()->brandEggState())->toBe(BrandEggState::SinGenerar);
});

/* --- the dependency ------------------------------------------------------ */

test('layer 5 is sent layer 2 output, and layer 2 is composed first', function () {
    $this->client->deliverables->update([
        DeliverableItem::Arquetipos->value => 'El Cuidador.',
        DeliverableItem::Valores->value => 'Cercanía, oficio, paciencia.',
        DeliverableItem::Manifesto->value => 'Creemos en la sobremesa.',
    ]);

    $composer = app(EggComposer::class);

    expect($composer->order())->toBe([
        BrandEggLayer::Esencia,
        BrandEggLayer::Personalidad,
        BrandEggLayer::Beneficios,
        BrandEggLayer::Assets,
        BrandEggLayer::Universo,
    ]);

    fakeParagraph('LA-PERSONALIDAD-COMPUESTA');
    $composer->compose($this->client->fresh(), BrandEggLayer::Personalidad);

    fakeParagraph('El universo.');
    $composer->compose($this->client->fresh(), BrandEggLayer::Universo);

    // The point of the dependency: layer 5 reads layer 2's RESULT, not its
    // sources over again, or the two layers restate one another.
    Http::assertSent(fn ($request) => str_contains(
        json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'LA-PERSONALIDAD-COMPUESTA',
    ));
});

test('layer 5 composed before layer 2 says the dependency is missing rather than pretending', function () {
    // Composing one ring out of order is a real thing to do — somebody clicked
    // that ring. What must not happen is layer 5 being written as though it had
    // read a Personalidad that does not exist.
    $this->client->deliverables->update([
        DeliverableItem::Valores->value => 'Cercanía, oficio, paciencia.',
    ]);

    fakeParagraph();
    app(EggComposer::class)->compose($this->client->fresh(), BrandEggLayer::Universo);

    Http::assertSent(function ($request) {
        $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

        return str_contains($sent, 'Personalidad') && str_contains($sent, 'NO DEFINIDO');
    });
});

/* --- the cached prefix --------------------------------------------------- */

test('the prefix is byte-identical across all five layers', function () {
    // Five layers each with their own system block is five separate prefixes
    // and five paid readings of the same instructions per brand, every time
    // somebody clicks Componer todo. The layer's name belongs in the user turn.
    $this->client->deliverables->update([
        DeliverableItem::Relato->value => 'Nació en una cocina de Guadalajara.',
        DeliverableItem::Valores->value => 'Cercanía, oficio, paciencia.',
        DeliverableItem::Arquetipos->value => 'El Cuidador.',
        DeliverableItem::Publicos->value => 'Gente de barrio.',
        DeliverableItem::LookAndFeel->value => 'Cálido, de madera.',
    ]);

    fakeParagraph();
    app(EggComposer::class)->composeAll($this->client->fresh());

    $prefixes = [];

    Http::assertSent(function ($request) use (&$prefixes) {
        $prefixes[] = json_encode($request->data()['messages'][0], JSON_UNESCAPED_UNICODE);

        return true;
    });

    expect($prefixes)->toHaveCount(5)
        ->and(array_unique($prefixes))->toHaveCount(1);
});

test('the brand name is in the user turn, never in the system block', function () {
    // The most obviously per-brand string in the call. One line of it above the
    // question gives every brand its own prefix — silently, at ~150x the cost.
    fillEsencia($this->client);
    fakeParagraph();

    app(EggComposer::class)->compose($this->client->fresh(), BrandEggLayer::Esencia);

    Http::assertSent(function ($request) {
        $messages = $request->data()['messages'];

        return ! str_contains(json_encode($messages[0], JSON_UNESCAPED_UNICODE), 'Cafetería Norte')
            && str_contains(json_encode($messages[1], JSON_UNESCAPED_UNICODE), 'Cafetería Norte');
    });
});

/* --- what composing writes, and what it must never write ----------------- */

test('composing never touches the entregables', function () {
    // The Egg is derived FROM them. A composer that could edit its own sources
    // would be the model authoring brand data with nobody in between —
    // CLAUDE.md section 8 rule 4.
    fillEsencia($this->client);

    $before = $this->client->deliverables->fresh()->toMarkdown();
    $stamp = $this->client->deliverables->fresh()->updated_at;

    fakeParagraph();
    app(EggComposer::class)->composeAll($this->client->fresh());

    expect($this->client->deliverables->fresh()->toMarkdown())->toBe($before)
        ->and($this->client->deliverables->fresh()->updated_at->eq($stamp))->toBeTrue();
});

test('a composed layer lands on the egg and moves it to sin aprobar', function () {
    fillEsencia($this->client);
    fakeParagraph('La esencia de la marca.');

    app(EggComposer::class)->compose($this->client->fresh(), BrandEggLayer::Esencia);

    $client = $this->client->fresh();

    expect($client->brandEgg->text(BrandEggLayer::Esencia))->toBe('La esencia de la marca.')
        ->and($client->brandEgg->generated_at)->not->toBeNull()
        ->and($client->brandEggState())->toBe(BrandEggState::SinAprobar);
});

test('a run that dies partway keeps the layers that landed', function () {
    // 100 seconds spent and nothing kept is the failure this prevents. The
    // screen then shows which rings are still hollow, and they are recomposed
    // one at a time.
    $this->client->deliverables->update([
        DeliverableItem::Relato->value => 'Nació en una cocina de Guadalajara.',
        DeliverableItem::Valores->value => 'Cercanía, oficio, paciencia.',
        DeliverableItem::Arquetipos->value => 'El Cuidador.',
    ]);

    $calls = 0;

    Http::fake(['api.deepseek.com/*' => function () use (&$calls) {
        $calls++;

        if ($calls > 1) {
            return Http::response('', 503);
        }

        return Http::response([
            'model' => 'deepseek-v4-pro',
            'choices' => [['message' => ['content' => 'La esencia.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 90],
        ]);
    }]);

    expect(fn () => app(EggComposer::class)->composeAll($this->client->fresh()))
        ->toThrow(ProviderUnavailable::class);

    expect($this->client->fresh()->brandEgg->text(BrandEggLayer::Esencia))->toBe('La esencia.');
});

test('a heading the model adds despite being asked not to is stripped', function () {
    // Saved as-is it would be read back later as though the brand had written
    // it — the Egg is prose the assistant quotes, not a document with sections.
    fillEsencia($this->client);
    fakeParagraph("## Esencia\n\nUna marca de barrio.");

    app(EggComposer::class)->compose($this->client->fresh(), BrandEggLayer::Esencia);

    expect($this->client->fresh()->brandEgg->text(BrandEggLayer::Esencia))
        ->toBe('Una marca de barrio.');
});

test('a failed composition is recorded in the ledger', function () {
    fillEsencia($this->client);

    Http::fake(['api.deepseek.com/*' => Http::response('', 503)]);

    expect(fn () => app(EggComposer::class)->compose($this->client->fresh(), BrandEggLayer::Esencia))
        ->toThrow(ProviderUnavailable::class);

    $this->assertDatabaseHas('ai_usage_logs', [
        'operation' => 'brand-egg-compose',
        'succeeded' => false,
    ]);
});

/* --- the enum is the vocabulary ------------------------------------------ */

test('every layer names real entregables, and the counts match the plan', function () {
    // docs/brand-egg.md section 2. A typo'd source would otherwise fail as a
    // silently thinner layer rather than as an error.
    $counts = [];

    foreach (BrandEggLayer::cases() as $layer) {
        foreach ($layer->sources() as $source) {
            expect($source)->toBeInstanceOf(DeliverableItem::class);
        }

        $counts[$layer->value] = count($layer->sources());
    }

    expect($counts)->toBe([
        'esencia' => 6,
        'personalidad' => 2,
        'beneficios' => 4,
        'assets' => 2,
        'universo' => 4,
    ]);
});
