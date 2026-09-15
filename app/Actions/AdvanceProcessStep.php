<?php

namespace App\Actions;

use App\Enums\ProcessStep;
use App\Models\Client;
use App\Models\ClientProcessStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Starts and closes the three steps of a brand's process.
 *
 * THE ONLY CLASS THAT WRITES client_process_steps. Everything else reads the
 * rows through Client::currentStep() and friends.
 *
 * Nothing here validates against the entregables. A step closes when the admin
 * says it closed — with zero entregables filled or with all 48. The system does
 * not have a better opinion than the person doing the work, and a gate here
 * would only teach people to fill boxes to unlock a button.
 *
 * ⚠️ EXACTLY ONE STEP MAY RUN AT A TIME — that invariant is real, unlike the
 * entregables one above, because Client::currentStep() is written as "the step
 * running right now" (singular) and the client-facing bar shows one "Paso X de
 * 3" line built from it. The process screen used to let an admin click
 * "Iniciar paso" on any not-yet-started step regardless of what else was
 * running, which left two rows both `isRunning()` at once. currentStep() then
 * returns whichever started FIRST — not the one anybody meant — so the client
 * bar freezes on the earlier step while the admin's own cards, which read each
 * step independently, correctly show two lit up. Found live on 2026-08-14:
 * Passiflor had both Arquitectura and Territorio running, reported by a tester
 * as "the title says paso 1 but we're on paso 2". start() now refuses rather
 * than create a second one — see runningStepOtherThan() and its own docblock
 * for why reopen() is deliberately not guarded the same way.
 */
class AdvanceProcessStep
{
    /**
     * Begin a step. Idempotent: starting one that is already running is a
     * no-op rather than an error, because two admins clicking at once is a
     * race, not a mistake anyone should see a 500 for.
     *
     * Returns null, touching nothing, if a DIFFERENT step is already running —
     * see the class docblock. The caller is expected to tell the admin which
     * one to close first rather than silently create a second "current" step.
     */
    public function start(Client $client, ProcessStep $step): ?ClientProcessStep
    {
        $existing = $client->processSteps()->firstWhere('step', $step);

        if ($existing?->isRunning()) {
            return $existing;
        }

        if ($this->runningStepOtherThan($client, $step) !== null) {
            return null;
        }

        $record = $client->processSteps()->firstOrCreate(
            ['step' => $step],
            ['started_at' => now()],
        );

        // A step that was closed and is being started again reopens, keeping
        // its original started_at: the timeline should say when the work began,
        // not when somebody corrected a misclick.
        if ($record->isComplete()) {
            $record->update(['completed_at' => null, 'completed_by' => null]);
        }

        return $record->refresh();
    }

    /**
     * Close a step and start the next one in the same transaction.
     *
     * The two together are the whole mechanism the admin was promised: mark
     * complete, the next one begins. Splitting them across two writes would
     * leave a brand with no running step if the second failed.
     */
    public function complete(Client $client, ProcessStep $step, User $by): ClientProcessStep
    {
        return DB::transaction(function () use ($client, $step, $by) {
            $record = $client->processSteps()->firstOrCreate(
                ['step' => $step],
                ['started_at' => now()],
            );

            $record->update([
                'completed_at' => now(),
                'completed_by' => $by->getKey(),
            ]);

            $next = $step->next();

            if ($next !== null) {
                $client->processSteps()->firstOrCreate(
                    ['step' => $next],
                    ['started_at' => now()],
                );
            }

            $client->unsetRelation('processSteps');

            return $record->refresh();
        });
    }

    /**
     * Undo a close without touching when the work started.
     *
     * ⚠️ DELIBERATELY NOT GUARDED like start() — this is the one place two
     * steps are allowed to read as "running" at once, on purpose. complete()
     * auto-starts the next step in the same breath it closes this one; undoing
     * a misclick means reopening THIS step while that auto-started next one is
     * still sitting there, and currentStep() then correctly falls back to this
     * one because it started first. Blocking that would break the recovery
     * path the "reopening a step keeps when the work began" test exists to
     * protect. The bug this class actually guards against — two steps started
     * INDEPENDENTLY by hand, with no completion between them — cannot happen
     * through reopen(): the button only ever appears on an already-completed
     * step, and reopening one changes nothing about any other row.
     */
    public function reopen(Client $client, ProcessStep $step): ?ClientProcessStep
    {
        $record = $client->processSteps()->where('step', $step)->first();

        $record?->update(['completed_at' => null, 'completed_by' => null]);

        $client->unsetRelation('processSteps');

        return $record;
    }

    /**
     * Whichever OTHER step is currently running, if any.
     *
     * start()'s one guard — see the class docblock for why reopen() does not
     * share it. $except is excluded so a step can ask "is anything ELSE
     * running" without tripping over the row it is about to touch itself.
     */
    private function runningStepOtherThan(Client $client, ProcessStep $except): ?ClientProcessStep
    {
        return $client->processSteps()
            ->get()
            ->first(fn (ClientProcessStep $record) => $record->step !== $except && $record->isRunning());
    }
}
