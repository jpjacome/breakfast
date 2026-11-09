{{--
    The three steps of a brand's process.

    ⚠️ A COMPONENT BECAUSE IT MOVED, NOT BECAUSE IT IS RENDERED TWICE. It lived
    on the process board, above the 48 entregables, and Breakfast asked for it
    on the BRAND page instead (2026-09-17): the board is the entregables, and
    the steps are a different question — where the project is, which is what the
    brand page answers. Lifting it into a component rather than moving the
    markup means the day it is wanted in a second place, there is one of it.

    ⚠️ IT COMPUTES ITS OWN STATE from the client, so a caller never has to pass
    `$completed` or `$current` and cannot pass a stale one. `stepRecord()` is
    the only way in.

    ⚠️ ITS WRITES STILL POST TO admin.clients.process.step, which answers with
    back() — so a click returns to whichever screen it came from, exactly like
    every other brand-scoped write (CLAUDE.md §11).

    Needs `process.css`, which the rendering page names.
--}}
@props(['client'])

@php
    use App\Enums\ProcessStep;

    $completed = $client->completedStepCount();
    $current = $client->currentStep();
@endphp

<section class="process-steps" aria-label="Pasos del proceso">

    <header class="process-steps-head">
        <h3 class="admin-heading">el proceso</h3>
        <p class="admin-hint">
            {{ $completed }} de {{ ProcessStep::count() }} pasos completos.
            Un paso se cierra cuando tú lo dices — no depende de los entregables.
        </p>
    </header>

    <ol class="process-step-cards">
        @foreach (ProcessStep::cases() as $step)
            @php
                $record = $client->stepRecord($step);
                $isRunning = $record?->isRunning() ?? false;
                $isComplete = $record?->isComplete() ?? false;
            @endphp

            <li class="process-step
                       @if($isComplete) process-step-done @elseif($isRunning) process-step-live @endif">

                <p class="process-step-number admin-numbers">
                    {{ $step->number() }}<span>/{{ ProcessStep::count() }}</span>
                </p>

                <h4 class="process-step-name">
                    <x-dynamic-component :component="'tabler-'.$step->icon()" aria-hidden="true" />
                    {{ $step->label() }}
                </h4>

                <p class="process-step-note">{{ $step->description() }}</p>

                <p class="process-step-state">
                    @if ($isComplete)
                        Completo el {{ $record->completed_at->translatedFormat('j \d\e F \d\e Y') }}
                        @if ($record->completedBy) · {{ $record->completedBy->name }} @endif
                    @elseif ($isRunning)
                        En curso desde el {{ $record->started_at->translatedFormat('j \d\e F \d\e Y') }}
                    @else
                        Sin empezar
                    @endif
                </p>

                <form method="POST" action="{{ route('admin.clients.process.step', $client) }}"
                      class="process-step-actions">
                    @csrf
                    <input type="hidden" name="step" value="{{ $step->value }}">

                    @if ($isComplete)
                        <button type="submit" name="action" value="reabrir"
                                class="admin-button admin-button-ghost admin-button-sm">
                            Reabrir
                        </button>
                    @elseif ($isRunning)
                        <button type="submit" name="action" value="completar"
                                class="admin-button admin-button-primary admin-button-sm">
                            Marcar completo
                        </button>
                    @elseif ($current !== null)
                        {{-- Disabled rather than hidden, and said why: an admin
                             who clicks this anyway would just get the same
                             sentence back as a flash message, one click later.
                             Only one step may run at a time — see
                             AdvanceProcessStep. --}}
                        <button type="button" class="admin-button admin-button-secondary admin-button-sm"
                                disabled title="Cierra «{{ $current->label() }}» primero.">
                            Iniciar paso
                        </button>
                    @else
                        <button type="submit" name="action" value="iniciar"
                                class="admin-button admin-button-secondary admin-button-sm">
                            Iniciar paso
                        </button>
                    @endif
                </form>

            </li>
        @endforeach
    </ol>

</section>
