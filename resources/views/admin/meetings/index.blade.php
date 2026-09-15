{{--
    /admin/reuniones — every brand's meetings on one screen.

    The card on a brand page answers "when do we next see THIS brand". This
    answers "what does the week look like", which before meant opening every
    brand in turn.

    Every row names its brand, because on a list that crosses brands whose
    meeting it is is the first thing being looked for.
--}}
<x-layouts.app title="Reuniones" heading="reuniones" css="meetings">

    <x-slot:actions>
        <a href="{{ route('admin.meetings.create') }}" class="admin-button admin-button-primary">
            Nueva reunión
        </a>
    </x-slot:actions>

    <div class="admin-page-head">
        <div>
            <p class="admin-eyebrow">
                {{ $upcoming->count() }} {{ Str::plural('reunión', $upcoming->count()) }}
                por delante
            </p>
            <h2 class="admin-title">Agenda</h2>
        </div>
    </div>

    @if ($brands->isEmpty())
        <div class="admin-empty">
            <p>Todavía no hay marcas, así que no hay nada que agendar.</p>
        </div>
    @else

        {{-- ================================================================
             What is coming
             ================================================================ --}}
        <section class="meetings" aria-label="Próximas reuniones">
            <h3 class="admin-heading">próximas</h3>

            @if ($upcoming->isEmpty())
                <div class="admin-empty">
                    <p>No hay ninguna reunión agendada.</p>
                </div>
            @else
                <ul class="meeting-list">
                    @foreach ($upcoming as $meeting)
                        <x-admin.meeting-row :meeting="$meeting" :client="$meeting->client" brand />
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- ================================================================
             The month
             ================================================================ --}}
        <section class="meeting-calendar" aria-label="Calendario">

            <div class="admin-row admin-row-between" style="margin-bottom:1rem;flex-wrap:wrap;gap:1rem">
                <h3 class="admin-heading">{{ $month->translatedFormat('F \d\e Y') }}</h3>

                {{-- ?mes=YYYY-MM rather than an offset, so a link to a month
                     is still that month when somebody opens it tomorrow. --}}
                <div class="admin-row" style="gap:.5rem">
                    <a class="admin-button admin-button-ghost admin-button-sm"
                       href="{{ route('admin.meetings.index', ['mes' => $month->copy()->subMonth()->format('Y-m')]) }}">
                        ← {{ $month->copy()->subMonth()->translatedFormat('F') }}
                    </a>
                    @unless ($month->isSameMonth(now()))
                        <a class="admin-button admin-button-ghost admin-button-sm"
                           href="{{ route('admin.meetings.index') }}">Hoy</a>
                    @endunless
                    <a class="admin-button admin-button-ghost admin-button-sm"
                       href="{{ route('admin.meetings.index', ['mes' => $month->copy()->addMonth()->format('Y-m')]) }}">
                        {{ $month->copy()->addMonth()->translatedFormat('F') }} →
                    </a>
                </div>
            </div>

            <div class="calendar" role="table">
                <div class="calendar-head" role="row" aria-hidden="true">
                    {{-- Monday first. A calendar that starts on the wrong day
                         is read wrong at a glance. --}}
                    @foreach (['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'] as $day)
                        <span role="columnheader">{{ $day }}</span>
                    @endforeach
                </div>

                @foreach ($weeks as $week)
                    <div class="calendar-week" role="row">
                        @foreach ($week as $day)
                            <div role="cell"
                                 class="calendar-day
                                        @if (! $day['date']->isSameMonth($month)) calendar-day-outside @endif
                                        @if ($day['date']->isToday()) calendar-day-today @endif">

                                <span class="calendar-date">{{ $day['date']->day }}</span>

                                @foreach ($day['meetings'] as $meeting)
                                    <a class="calendar-meeting @if($meeting->isCancelled()) calendar-meeting-off @endif"
                                       href="{{ route('admin.clients.show', $meeting->client) }}"
                                       title="{{ $meeting->client->name }} — {{ $meeting->title }} · {{ $meeting->scheduled_at->format('H:i') }}">
                                        <b>{{ $meeting->scheduled_at->format('H:i') }}</b>
                                        <span>{{ $meeting->client->name }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ================================================================
             What already happened
             ================================================================ --}}
        @if ($past->isNotEmpty())
            <section class="meetings" aria-label="Reuniones pasadas">
                <h3 class="admin-heading">ya pasaron</h3>
                <ul class="meeting-list">
                    @foreach ($past as $meeting)
                        <x-admin.meeting-row :meeting="$meeting" :client="$meeting->client" brand />
                    @endforeach
                </ul>
            </section>
        @endif

    @endif

</x-layouts.app>
