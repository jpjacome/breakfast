<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\actingAs;

/**
 * How many assistant turns may run at once — and what happens to the third.
 *
 * ⚠️ WHAT THIS PROTECTS IS THE PUBLIC SITE. Every domain on the hosting account
 * shares one small pool of PHP workers. A turn that reads a brandbook holds one
 * for the better part of a minute, and once enough are held the front end stops
 * waiting and answers everything else with a small-body 503 — the marketing
 * site, a client's dashboard, a login. Measured live on 2026-08-18: requests to
 * vamosdebreakfast.com were refused in 15 seconds flat while three long
 * requests were in flight, and none of it reached laravel.log because the
 * worker dies before Laravel's exception handler runs.
 *
 * The `throttle` middleware cannot do this job and is not a substitute: it
 * counts requests per minute, and twenty ninety-second requests inside one
 * minute is both within throttle:10,1 and every worker gone.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->client = Client::factory()->create(['name' => 'Cafetería Norte']);
});

/** Hold every slot the middleware has, as two turns already running would. */
function holdEveryAiSlot(): array
{
    return collect([1, 2])
        ->map(function (int $slot) {
            $lock = Cache::lock("ai-turn-slot-{$slot}", 150);
            expect($lock->get())->toBeTrue();

            return $lock;
        })
        ->all();
}

test('a turn arriving while every slot is taken is refused, not queued', function () {
    $held = holdEveryAiSlot();

    actingAs($this->admin)
        ->postJson(route('admin.clients.process.assistant', $this->client), [
            'message' => '¿Qué falta por definir?',
        ])
        // 429 rather than 503: the service is fine, it is busy. And a sentence
        // rather than a blank refusal, because somebody told to wait ten
        // seconds waits, while somebody told nothing presses send again — which
        // is what turns one slow turn into a pool with nothing left in it.
        ->assertStatus(429)
        ->assertJsonStructure(['error']);

    foreach ($held as $lock) {
        $lock->release();
    }
});

test('every AI surface carries the guard, not just the one that reads files', function () {
    // A brandbook is the worst case, but any turn holds a worker, and the
    // dashboard assistant reads every brand in the database.
    $held = holdEveryAiSlot();

    actingAs($this->admin)
        ->postJson(route('admin.assistant'), ['question' => '¿Cómo vamos?'])
        ->assertStatus(429);

    actingAs($this->admin)
        ->postJson(route('admin.clients.draft.assistant'), ['message' => 'Una marca nueva'])
        ->assertStatus(429);

    foreach ($held as $lock) {
        $lock->release();
    }
});

test('the slot is given back when the turn fails, not just when it succeeds', function () {
    // The release lives in a finally. Without it the first provider outage
    // would wedge the assistant shut for everybody until the TTL expired —
    // a failure mode strictly worse than the one being prevented.
    Cache::lock('ai-turn-slot-1', 150)->forceRelease();
    Cache::lock('ai-turn-slot-2', 150)->forceRelease();

    // No Http::fake() here, and Pest.php calls preventStrayRequests(), so the
    // turn throws inside the controller — the failure path this is about.
    actingAs($this->admin)
        ->postJson(route('admin.clients.process.assistant', $this->client), [
            'message' => 'Algo',
        ]);

    $free = Cache::lock('ai-turn-slot-1', 1);

    expect($free->get())->toBeTrue();

    $free->release();
});

test('a second turn is let through — this bounds concurrency, it is not a queue of one', function () {
    // Two people at Breakfast working at the same time is the normal case, and
    // three concurrent short requests were measured fine on the live host. A
    // guard that allowed only one would be its own outage.
    $first = Cache::lock('ai-turn-slot-1', 150);

    expect($first->get())->toBeTrue();

    $response = actingAs($this->admin)
        ->postJson(route('admin.clients.process.assistant', $this->client), [
            'message' => 'La segunda consulta',
        ]);

    // Whatever it answers — the provider is unreachable in tests — it must not
    // be the "busy" refusal, because the second slot was free.
    expect($response->status())->not->toBe(429);

    $first->release();
});
