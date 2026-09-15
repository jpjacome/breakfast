{{--
    Reuniones, from the client's side. Read-only — Breakfast schedules them.

    The next one is the whole point of the page and gets the top of it; the
    rest is history worth keeping for the notes.
--}}
<x-layouts.portal title="Reuniones" :section="$section">

    <header class="dashboard-head">
        <h1>Reuniones</h1>
        <p>Lo que viene con tu equipo de Breakfast, y lo que ya pasó.</p>
    </header>

    @if ($upcoming->isNotEmpty())
        @php $next = $upcoming->first(); @endphp

        <article class="meeting-next">
            <p class="meeting-eyebrow">Próxima reunión</p>
            <h2>{{ $next->title }}</h2>
            <p class="meeting-when">{{ ucfirst($next->whenInWords()) }}</p>

            @if ($next->agenda)
                <p class="meeting-agenda">{{ $next->agenda }}</p>
            @endif

            @if ($next->link)
                <a href="{{ $next->link }}" class="dashboard-button" target="_blank" rel="noopener">
                    Unirse
                </a>
            @endif
        </article>

        @if ($upcoming->count() > 1)
            <section class="meeting-block">
                <h3>Después de esa</h3>
                <ul class="meeting-list">
                    @foreach ($upcoming->skip(1) as $meeting)
                        <li class="meeting-row">
                            <b>{{ $meeting->title }}</b>
                            <span>{{ ucfirst($meeting->whenInWords()) }}</span>
                            @if ($meeting->link)
                                <a href="{{ $meeting->link }}" target="_blank" rel="noopener">Link</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @else
        {{-- Said plainly rather than left as an empty page, which reads as
             something that failed to load. --}}
        <p class="dashboard-empty">
            No hay ninguna reunión agendada por ahora. Cuando tu equipo de
            Breakfast agende una, la verás aquí y te avisaremos.
        </p>
    @endif

    @if ($past->isNotEmpty())
        <section class="meeting-block">
            <h3>Ya pasaron</h3>
            <ul class="meeting-list">
                @foreach ($past as $meeting)
                    <li class="meeting-row @if($meeting->isCancelled()) meeting-row-off @endif">
                        <b>{{ $meeting->title }}</b>
                        <span>
                            {{ ucfirst($meeting->whenInWords()) }}
                            @if ($meeting->isCancelled()) · cancelada @endif
                        </span>
                        @if ($meeting->notes)
                            <p class="meeting-notes">{{ $meeting->notes }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

</x-layouts.portal>
