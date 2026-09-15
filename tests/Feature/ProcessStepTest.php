<?php

use App\Actions\AdvanceProcessStep;
use App\Enums\ProcessStep;
use App\Models\Client;
use App\Models\User;
use Carbon\Carbon;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->client = Client::factory()->create();
    $this->steps = app(AdvanceProcessStep::class);
});

test('a brand starts with no step running', function () {
    expect($this->client->currentStep())->toBeNull()
        ->and($this->client->completedStepCount())->toBe(0);
});

test('starting the first step makes it the current one', function () {
    $this->steps->start($this->client, ProcessStep::Arquitectura);
    $this->client->unsetRelation('processSteps');

    expect($this->client->currentStep())->toBe(ProcessStep::Arquitectura)
        ->and($this->client->stepRecord(ProcessStep::Arquitectura)->isRunning())->toBeTrue();
});

test('completing a step starts the next one', function () {
    $this->steps->start($this->client, ProcessStep::Arquitectura);
    $this->steps->complete($this->client, ProcessStep::Arquitectura, $this->admin);
    $this->client->unsetRelation('processSteps');

    expect($this->client->currentStep())->toBe(ProcessStep::Territorio)
        ->and($this->client->completedStepCount())->toBe(1)
        ->and($this->client->stepRecord(ProcessStep::Arquitectura)->completedBy->is($this->admin))->toBeTrue();
});

test('completing the last step leaves nothing running', function () {
    foreach (ProcessStep::cases() as $step) {
        $this->steps->complete($this->client, $step, $this->admin);
    }

    $this->client->unsetRelation('processSteps');

    expect($this->client->currentStep())->toBeNull()
        ->and($this->client->completedStepCount())->toBe(3);
});

test('a step closes with no entregables filled', function () {
    // Nothing validates against the entregables on purpose: the admin knows
    // when a step ended, and a gate here would only teach people to fill
    // boxes to unlock a button.
    $this->steps->complete($this->client, ProcessStep::Arquitectura, $this->admin);
    $this->client->unsetRelation('processSteps');

    expect($this->client->completedStepCount())->toBe(1)
        ->and($this->client->deliverables->filledCount())->toBe(0);
});

test('starting a step twice is a no-op, not an error', function () {
    $first = $this->steps->start($this->client, ProcessStep::Arquitectura);
    $this->client->unsetRelation('processSteps');
    $again = $this->steps->start($this->client, ProcessStep::Arquitectura);

    expect($again->id)->toBe($first->id)
        ->and($this->client->processSteps()->count())->toBe(1);
});

test('reopening a step keeps when the work began', function () {
    $started = $this->steps->start($this->client, ProcessStep::Arquitectura)->started_at;

    $this->steps->complete($this->client, ProcessStep::Arquitectura, $this->admin);
    $this->steps->reopen($this->client, ProcessStep::Arquitectura);
    $this->client->unsetRelation('processSteps');

    $record = $this->client->stepRecord(ProcessStep::Arquitectura);

    expect($record->isComplete())->toBeFalse()
        // The timeline should say when the work began, not when somebody
        // corrected a misclick.
        ->and($record->started_at->timestamp)->toBe($started->timestamp);
});

/* -------------------------------------------------------------------------
 | The clock
 ------------------------------------------------------------------------- */

/**
 * Regression, reported by a tester on 2026-08-13: starting Arquitectura that
 * evening recorded "En curso desde el 14 de agosto de 2026".
 *
 * The app ran on UTC while the people using it are five hours behind, so from
 * 19:00 local onwards now() had already rolled to tomorrow. 01:30 UTC on the
 * 14th IS 20:30 on the 13th in Guayaquil, and the 13th is what the screen has
 * to say.
 */
test('a step started in the evening is dated today, not tomorrow', function () {
    $this->travelTo(Carbon::parse('2026-08-14 01:30:00', 'UTC'));

    $record = $this->steps->start($this->client, ProcessStep::Arquitectura);

    expect($record->started_at->toDateString())->toBe('2026-08-13')
        ->and($record->started_at->format('H:i'))->toBe('20:30');

    $this->travelBack();
});

/** The label the tester actually read, rendered the way the blade renders it. */
test('the process screen dates an evening step as the day it was started', function () {
    $this->travelTo(Carbon::parse('2026-08-14 01:30:00', 'UTC'));

    $this->steps->start($this->client, ProcessStep::Arquitectura);

    actingAs($this->admin)
        ->get(route('admin.clients.process.edit', $this->client))
        ->assertOk()
        ->assertSee('En curso desde el 13 de agosto de 2026')
        ->assertDontSee('En curso desde el 14 de agosto de 2026');

    $this->travelBack();
});
