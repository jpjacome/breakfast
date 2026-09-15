{{--
    The three steps, as the client sees them.

    The only progress the client is ever shown. The 48 entregables are the
    admin's board and never appear here — a list of 28 optional things nobody
    promised would read as 28 things missing.

    $compact drops the descriptions and the dates, for the dashboard where this
    sits above everything else and should not become the page.

    Props:
      client   the brand
      compact  the dashboard's shorter form. The full one has no home
               since /portal/proyecto was removed; kept for when one wants it
--}}
@props(['client', 'compact' => false])

@php
    use App\Enums\ProcessStep;

    $current = $client->currentStep();
    $done = $client->completedStepCount();
    $total = count(ProcessStep::cases());
@endphp

<section class="step-bar {{ $compact ? 'step-bar-compact' : '' }}"
         aria-label="Avance del proyecto">

    <header class="step-bar-head">
        <p class="meeting-eyebrow">El proceso</p>
        <p class="step-bar-state">
            @if ($current)
                <b>Paso {{ $current->number() }} de {{ $total }}</b> · {{ $current->label() }}
            @elseif ($done === $total)
                <b>Los {{ $total }} pasos están completos.</b>
            @else
                <b>Todavía no empezamos.</b> Tu equipo de Breakfast arranca en cuanto esté listo.
            @endif
        </p>
    </header>

    <ol class="step-bar-steps">
        @foreach (ProcessStep::cases() as $step)
            @php
                $record = $client->stepRecord($step);
                $isDone = $record?->isComplete() ?? false;
                $isNow = $record?->isRunning() ?? false;
            @endphp

            <li class="step-bar-step @if($isDone) is-done @elseif($isNow) is-now @endif">
                <span class="step-bar-mark" aria-hidden="true">
                    @if ($isDone)
                        <x-tabler-check />
                    @else
                        {{ $step->number() }}
                    @endif
                </span>

                <span class="step-bar-name">{{ $step->label() }}</span>

                @unless ($compact)
                    <span class="step-bar-note">{{ $step->description() }}</span>

                    {{-- Absolute dates, and only ones that happened. A step
                         with no row has no date to show, and inventing "por
                         empezar · fecha" would promise a schedule nobody set. --}}
                    @if ($isDone)
                        <span class="step-bar-when">
                            Completo el {{ $record->completed_at->translatedFormat('j \d\e F \d\e Y') }}
                        </span>
                    @elseif ($isNow)
                        <span class="step-bar-when">
                            En curso desde el {{ $record->started_at->translatedFormat('j \d\e F \d\e Y') }}
                        </span>
                    @endif
                @endunless

                <span class="screen-reader-only">
                    @if ($isDone) completo @elseif ($isNow) en curso @else sin empezar @endif
                </span>
            </li>
        @endforeach
    </ol>

</section>
