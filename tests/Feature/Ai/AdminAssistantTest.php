<?php

declare(strict_types=1);

use App\Actions\AdvanceProcessStep;
use App\Enums\ClientStatus;
use App\Enums\DeliverableItem;
use App\Enums\ProcessStep;
use App\Models\Client;
use App\Models\User;
use App\Services\Ai\Admin\AdminContextBuilder;
use App\Services\Ai\Admin\PortfolioSnapshot;
use App\Services\Ai\Admin\SpendDigest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    config()->set('ai.providers.deepseek.api_key', 'sk-test');

    $this->admin = User::factory()->admin()->create();
    $this->coffee = Client::factory()->create(['name' => 'The Coffee Club', 'slug' => 'the-coffee-club']);
    $this->other = Client::factory()->create(['name' => 'Otra Marca', 'slug' => 'otra-marca']);
});

function fakeAdminReply(string $text = 'Ninguna marca está trabada.'): void
{
    Http::fake(['api.deepseek.com/*' => Http::response([
        'model' => 'deepseek-v4-pro',
        'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 60],
    ])]);
}

/*
|--------------------------------------------------------------------------
| The snapshot — what the model actually reads
|--------------------------------------------------------------------------
*/

test('the snapshot carries every brand with its state', function () {
    app(AdvanceProcessStep::class)->start($this->coffee, ProcessStep::Arquitectura);
    $this->coffee->deliverables->update([DeliverableItem::Relato->value => 'Nació en Guadalajara.']);
    $this->coffee->meetings()->create([
        'title' => 'Revisión de territorio',
        'scheduled_at' => now()->addWeek(),
    ]);

    $snapshot = app(PortfolioSnapshot::class)->forUser($this->admin);

    expect($snapshot)
        ->toContain('The Coffee Club')
        ->toContain('Otra Marca')
        ->toContain('1 de 3 · Identidad de marca')
        ->toContain('Revisión de territorio')
        // Spend is deliberately NOT here any more: it changes on every turn,
        // and this block is the cached one. See SpendDigest.
        ->not->toContain('Gasto de IA');
});

test('the snapshot is scoped to the brands the user may see', function () {
    $staff = User::factory()->equipo()->create();
    $this->coffee->staff()->attach($staff);

    $snapshot = app(PortfolioSnapshot::class)->forUser($staff->fresh());

    // Otherwise an Equipo member learns the roster by asking the assistant
    // what the brand list refuses to show them.
    expect($snapshot)->toContain('The Coffee Club')
        ->not->toContain('Otra Marca');
});

test('a user with no brands is told so rather than given an empty table', function () {
    expect(app(PortfolioSnapshot::class)->forUser(User::factory()->equipo()->create()))
        ->toContain('No hay ninguna marca asignada');
});

test('the snapshot carries no relative dates', function () {
    app(AdvanceProcessStep::class)->start($this->coffee, ProcessStep::Arquitectura);

    $snapshot = app(PortfolioSnapshot::class)->forUser($this->admin);

    // It sits in the cacheable prefix: "hace tres días" would change daily and
    // drop the hit rate to zero, silently.
    expect($snapshot)->not->toContain('hace ')
        ->not->toContain('Hoy es');
});

/*
|--------------------------------------------------------------------------
| The prompt
|--------------------------------------------------------------------------
*/

test('today rides on the question, never above it', function () {
    $builder = app(AdminContextBuilder::class);
    $messages = $builder->forQuestion($this->admin, '¿Qué marcas están trabadas?');

    expect(end($messages)->text())->toContain('Hoy es ');

    foreach ($builder->cacheablePrefix($this->admin) as $message) {
        expect($message->text())->not->toContain('Hoy es ');
    }
});

test('naming a brand appends its ficha without dropping the others', function () {
    $prefix = app(AdminContextBuilder::class)->cacheablePrefix($this->admin, $this->coffee);
    $context = collect($prefix)->map(fn ($m) => $m->text())->implode("\n");

    // "¿Cómo va X comparada con las demás?" names one brand and still needs
    // the rest, so the dropdown is a hint about focus, not a filter.
    expect($context)->toContain('Ficha ampliada')
        ->toContain('The Coffee Club')
        ->toContain('Otra Marca');
});

test('the prefix is stable between questions', function () {
    $builder = app(AdminContextBuilder::class);

    $first = collect($builder->cacheablePrefix($this->admin))->map->text()->implode('');
    $second = collect($builder->cacheablePrefix($this->admin))->map->text()->implode('');

    expect($first)->toBe($second);
});

/*
|--------------------------------------------------------------------------
| The endpoint
|--------------------------------------------------------------------------
*/

test('an admin gets an answer', function () {
    fakeAdminReply('Ninguna marca está trabada.');

    actingAs($this->admin)
        ->postJson(route('admin.assistant'), ['question' => '¿Qué marcas están trabadas?'])
        ->assertOk()
        ->assertJson(['reply' => 'Ninguna marca está trabada.']);
});

test('picking a brand reports which one was used', function () {
    fakeAdminReply();

    actingAs($this->admin)
        ->postJson(route('admin.assistant'), [
            'question' => '¿Cómo va?',
            'brand' => 'the-coffee-club',
        ])
        ->assertOk()
        ->assertJson(['brand' => 'The Coffee Club']);
});

test('a brand the user cannot see falls back to the portfolio, not an error', function () {
    fakeAdminReply();

    $staff = User::factory()->equipo()->create();
    $this->coffee->staff()->attach($staff);

    // Hand-posting another brand's slug must not fetch its ficha. The snapshot
    // is already scoped, so the answer stays correct rather than erroring.
    actingAs($staff->fresh())
        ->postJson(route('admin.assistant'), [
            'question' => '¿Cómo va?',
            'brand' => 'otra-marca',
        ])
        ->assertOk()
        ->assertJson(['brand' => null]);
});

test('client users cannot reach it', function () {
    $owner = User::factory()->clientOwner()->create(['client_id' => $this->coffee->id]);

    // 404, not 403 — the admin area does not exist for them.
    actingAs($owner)
        ->postJson(route('admin.assistant'), ['question' => '¿Cómo va todo?'])
        ->assertNotFound();
});

test('an empty question is refused before any api call', function () {
    // A recorder has to exist before assertNothingSent() can read it, and
    // faking nothing is also what proves the call never happened: an unfaked
    // request would be thrown by Http::preventStrayRequests() in Pest.php.
    Http::fake();

    actingAs($this->admin)
        ->postJson(route('admin.assistant'), ['question' => '  '])
        ->assertStatus(422);

    Http::assertNothingSent();
});

test('the answer is recorded in the usage ledger against the brand asked about', function () {
    fakeAdminReply();

    actingAs($this->admin)->postJson(route('admin.assistant'), [
        'question' => '¿Cómo va?',
        'brand' => 'the-coffee-club',
    ])->assertOk();

    $this->assertDatabaseHas('ai_usage_logs', [
        'client_id' => $this->coffee->id,
        'operation' => 'admin-question',
    ]);
});

test('a provider failure is reported, not swallowed', function () {
    Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'nope']], 500)]);

    actingAs($this->admin)
        ->postJson(route('admin.assistant'), ['question' => '¿Cómo va?'])
        ->assertStatus(502);
});

/*
|--------------------------------------------------------------------------
| Where the assistant appears
|--------------------------------------------------------------------------
*/

test('the portfolio assistant is on the dashboard', function () {
    actingAs($this->admin)->get(route('admin.home'))
        ->assertOk()
        ->assertSee('data-assistant', escape: false)
        ->assertSee(route('admin.assistant'), escape: false)
        ->assertSee('Todas las marcas');
});

test('the new-brand screen carries the entregables assistant, not this one', function () {
    // Same agent, different job: on /clientes/nueva it is there to fill in the
    // brand's missing entregables from whatever you hand it, so it is the one
    // that reads files and proposes — see BrandOnboardingController::draft().
    actingAs($this->admin)->get(route('admin.clients.create'))
        ->assertOk()
        ->assertSee('data-brand-assistant', escape: false)
        ->assertSee(route('admin.clients.draft.assistant'), escape: false)
        ->assertDontSee(route('admin.assistant'), escape: false);
});

test('the prompt carries the screen map so it can point rather than act', function () {
    // The assistant is read-only, which is only useful if it can say where the
    // thing IS done. Verified against a real model on 2026-08-13: asked to
    // create a brand it answered "/admin/clientes/nueva"; asked to schedule a
    // meeting it refused and named that brand's proceso screen.
    $prompt = (string) config('ai.admin_prompt');

    expect($prompt)
        ->toContain('/admin/clientes/nueva')
        ->toContain('/admin/clientes/{slug}/proceso')
        ->toContain('/admin/equipo')
        ->toContain('No ejecutas nada');
});

/*
|--------------------------------------------------------------------------
| What a tester found missing — 2026-08-14
|--------------------------------------------------------------------------
| Four questions the assistant answered with "No tengo ese dato aquí" while
| the answer sat in the database. Each one is a hole in the context, not a
| failure of the model: the prompt's rule is that a figure must appear
| literally in the context, so anything the context omits is unanswerable by
| construction.
*/

test('the snapshot dates when each brand was added', function () {
    $this->coffee->update(['onboarded_at' => now()->subMonth()]);

    // A draft is not "added" yet: onboarded_at is stamped when the draft is
    // finished, not when the half-filled form was opened.
    Client::factory()->create([
        'name' => 'Media Marca',
        'status' => ClientStatus::Borrador,
        'onboarded_at' => null,
    ]);

    // "¿Cuál es la última marca agregada?" needs a date per row to sort by.
    expect(app(PortfolioSnapshot::class)->forUser($this->admin))
        ->toContain('Alta: ')
        ->toContain($this->coffee->onboarded_at->translatedFormat('j \d\e F \d\e Y'))
        // Saying so beats an absent line: an omitted field is a hole the model
        // fills with something plausible.
        ->toContain('todavía es borrador');
});

test('the snapshot spells out how many entregables are left', function () {
    $this->coffee->deliverables->update([DeliverableItem::Relato->value => 'Nació en Guadalajara.']);

    // "¿Cuánto le falta?" must not require the model to subtract in its head:
    // the rule is that every figure appears literally.
    expect(app(PortfolioSnapshot::class)->forUser($this->admin))
        ->toContain('Entregables completados: 1 de 48 (faltan 47)')
        ->toContain('Entregables obligatorios:');
});

test('a brand ficha carries the entregables themselves, not just the count', function () {
    $this->coffee->deliverables->update([
        DeliverableItem::Colores->value => 'Primario #ECBB12. Tinta #000000.',
    ]);

    // The tester asked "¿ya tiene colores definidos?" about a brand whose
    // colours were filled in, and was told the data was not available.
    expect(app(PortfolioSnapshot::class)->ficha($this->coffee))
        ->toContain('#ECBB12')
        ->toContain('Colores');
});

test('the ficha says which entregables are undefined rather than omitting them', function () {
    // Silence gets completed by the model with whatever is most probable for
    // the category; an explicit NO DEFINIDO does not.
    expect(app(PortfolioSnapshot::class)->ficha($this->coffee))
        ->toContain('NO DEFINIDO');
});

test('naming a brand in the question attaches its ficha without the dropdown', function () {
    $this->coffee->deliverables->update([
        DeliverableItem::Colores->value => 'Primario #ECBB12.',
    ]);

    // No brand passed: this is "todas las marcas" mode, which is where the
    // tester was when the assistant said it could not see the data.
    $prefix = collect(app(AdminContextBuilder::class)
        ->cacheablePrefix($this->admin, null, '¿the coffee club ya tiene colores definidos?'))
        ->map->text()
        ->implode('');

    expect($prefix)->toContain('#ECBB12')
        ->and($prefix)->toContain('Ficha ampliada');
});

test('a brand named in the question is still scoped to what the asker may see', function () {
    // The assistant must not become the way around the brand scope: naming a
    // brand you were never put on finds nothing.
    $staff = User::factory()->equipo()->create();
    $this->coffee->staff()->attach($staff);
    $this->other->deliverables->update([DeliverableItem::Colores->value => 'Secreto #123456.']);

    $prefix = collect(app(AdminContextBuilder::class)
        ->cacheablePrefix($staff, null, '¿cómo va otra marca?'))
        ->map->text()
        ->implode('');

    expect($prefix)->not->toContain('#123456')
        ->and($prefix)->not->toContain('Otra Marca');
});

test('a name inside a longer word does not drag in the wrong brand', function () {
    Client::factory()->create(['name' => 'Alea', 'slug' => 'alea']);

    $prefix = collect(app(AdminContextBuilder::class)
        ->cacheablePrefix($this->admin, null, 'dame algo aleatorio'))
        ->map->text()
        ->implode('');

    expect($prefix)->not->toContain('Ficha ampliada');
});

test('two turns naming the same brand still serialise identically', function () {
    // The mentions make block 2 vary with the question, which costs cache hits
    // on those turns. Consecutive turns about the same brand must still hit.
    $builder = app(AdminContextBuilder::class);
    $question = '¿the coffee club ya tiene colores?';

    $first = collect($builder->cacheablePrefix($this->admin, null, $question))->map->text()->implode('');
    $second = collect($builder->cacheablePrefix($this->admin, null, $question))->map->text()->implode('');

    expect($first)->toBe($second);
});

/*
|--------------------------------------------------------------------------
| Spend — asked in general, or about one brand
|--------------------------------------------------------------------------
*/

/** One ledger row, the shape UsageRecorder writes. */
function logSpend(?Client $client, int $microUsd): void
{
    DB::table('ai_usage_logs')->insert([
        'client_id' => $client?->id,
        'user_id' => null,
        'operation' => 'admin-question',
        'provider' => 'deepseek',
        'model' => 'deepseek-v4-pro',
        'prompt_tokens' => 900,
        'completion_tokens' => 40,
        'cache_hit_tokens' => 0,
        'cost_micro_usd' => $microUsd,
        'cost_is_reported' => false,
        'succeeded' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('the spend digest answers both the general and the per-brand question', function () {
    logSpend($this->coffee, 250_000);   // $0.25
    logSpend($this->other, 40_000);     // $0.04
    logSpend(null, 10_000);             // Breakfast's own overhead

    $digest = app(SpendDigest::class)->forUser($this->admin);

    expect($digest)
        // The general total, already summed: the model is forbidden from
        // adding the brands up itself.
        ->toContain('$0.30')
        // ...and the per-brand breakdown, dearest first.
        ->toContain('The Coffee Club')
        ->toContain('$0.25')
        ->toContain('Otra Marca')
        // Unattributed spend is named as what it is, never as a brand.
        ->toContain('Sin marca');
});

test('the dearest brand is first, so it is read rather than calculated', function () {
    logSpend($this->other, 90_000);
    logSpend($this->coffee, 10_000);

    $digest = app(SpendDigest::class)->forUser($this->admin);

    expect(strpos($digest, 'Otra Marca'))->toBeLessThan(strpos($digest, 'The Coffee Club'));
});

test('spend is scoped to the brands the asker may see', function () {
    $staff = User::factory()->equipo()->create();
    $this->coffee->staff()->attach($staff);

    logSpend($this->coffee, 250_000);
    logSpend($this->other, 999_000);

    $digest = app(SpendDigest::class)->forUser($staff);

    // Naming a brand's cost is naming the brand. Otra Marca is not theirs.
    expect($digest)->toContain('The Coffee Club')
        ->and($digest)->not->toContain('Otra Marca');
});

/**
 * ⚠️ The reason SpendDigest exists as its own class rather than two more lines
 * in PortfolioSnapshot. Answering a question writes a ledger row, so spend in
 * the cacheable prefix would change it every single turn and take the hit rate
 * to zero — silently, with costs up ~150x. The spending report making every
 * request dearer is the joke this test is here to prevent.
 */
test('spend never reaches the cacheable prefix', function () {
    $builder = app(AdminContextBuilder::class);

    $before = collect($builder->cacheablePrefix($this->admin))->map->text()->implode('');

    logSpend($this->coffee, 500_000);

    $after = collect($builder->cacheablePrefix($this->admin))->map->text()->implode('');

    // Byte-identical after a ledger write is the whole invariant.
    expect($after)->toBe($before)
        // The system prompt explains where spend comes from and is stable; the
        // figures are what must not be here. "Últimos 30 días:" is a line only
        // the digest writes.
        ->and($after)->not->toContain('Últimos 30 días:')
        ->and($after)->not->toContain('$0.');
});

test('the question turn carries the spend block', function () {
    logSpend($this->coffee, 250_000);

    $messages = app(AdminContextBuilder::class)
        ->forQuestion($this->admin, '¿cuánto llevamos gastando en IA?');

    // Last message is the user turn: today, then spend, then the question.
    expect(end($messages)->text())
        ->toContain('Gasto de IA')
        ->toContain('$0.25')
        ->toContain('¿cuánto llevamos gastando en IA?');
});

test('the dashboard assistant is Brandy too', function () {
    $prompt = (string) config('ai.admin_prompt');

    // One agent, two contexts — never two agents. The client's Brandy answers
    // about one brand from its entregables; this one about every brand from
    // the database.
    expect($prompt)->toContain('Brandy')
        ->toContain('The Brand Therapist')
        // ...and the rule that makes THIS side safe survives the persona: a
        // made-up figure reads exactly like a real one.
        ->toContain('LITERALMENTE');
});
