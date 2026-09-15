{{--
    /portal — what this user can actually reach, as cards.

    Deliberately the same list as the sidebar: a member should be able to see
    the whole of their portal on one screen and find nothing missing.
--}}
@php
    use App\Enums\PortalSection;

    $user = auth()->user();
    $sections = array_filter(
        $user->visibleSections(),
        fn (PortalSection $s) => ! $s->isAlwaysOn(),
    );

    // Both gated on the section they belong to. Showing either to somebody who
    // was never granted it would tell them their brand has one.
    $nextMeeting = $user->canRead(PortalSection::Reuniones)
        ? $user->activeBrand()?->nextMeeting()
        : null;

    // No section gate any more: Proyecto was the page this used to belong to,
    // and the dashboard is every brand person's own screen. Where their brand
    // is up to is the least private thing on it.
    $showSteps = $user->activeBrand() !== null;

    // Whether Brandy has anything to answer from. The same test
    // BrandContextRepository::hasUsableContext() makes — one filled entregable
    // is the difference between an assistant and an empty promise.
    $written = ($user->activeBrand()?->deliverablesOrNew()->filledCount() ?? 0) > 0;
@endphp

<x-layouts.portal title="Inicio" :css="['assistant', 'attachments']"
                  :scripts="['resources/js/assistant.js', 'resources/js/lightbox.js']">

    {{--
        Brandy introduces herself: the assistant is the first thing on this
        screen, and an orb with no name is a widget rather than somebody to ask.

        A brand with nothing written keeps its own line. Inviting questions
        there is an invitation to be told "todavía no hay nada escrito", which
        is a worse first impression than saying so up front.
    --}}
    <header class="dashboard-head">
        <h1>Hola, {{ Str::of($user->name)->explode(' ')->first() }}.</h1>
        <p>
            Soy <b>Brandy</b>, tu asistente de marca.
            @if ($written)
                Pregúntame lo que quieras sobre {{ $user->activeBrand()->name }}.
            @else
                Todavía no hay nada escrito de
                {{ $user->activeBrand()?->name ?? 'tu marca' }} — en cuanto avancemos, pregúntame.
            @endif
        </p>
    </header>

    {{-- The assistant sits where the Breakfast side's does: first thing on the
         dashboard, above everything else. Same panel, same JavaScript — it
         answers from this brand's entregables and nothing else. --}}
    <x-portal.assistant />

    {{-- Above the section grid: "where are we" and "when do we meet next" are
         the two questions this screen gets asked most, and neither should need
         a click to answer. --}}
    @if ($showSteps)
        <x-portal.step-bar :client="$user->activeBrand()" compact />
    @endif

    @if ($nextMeeting)
        <article class="meeting-next">
            <p class="meeting-eyebrow">Próxima reunión</p>
            <h2>{{ $nextMeeting->title }}</h2>
            <p class="meeting-when">{{ ucfirst($nextMeeting->whenInWords()) }}</p>

            @if ($nextMeeting->agenda)
                <p class="meeting-agenda">{{ $nextMeeting->agenda }}</p>
            @endif

            <p class="meeting-actions">
                @if ($nextMeeting->link)
                    <a href="{{ $nextMeeting->link }}" class="dashboard-button" target="_blank" rel="noopener">
                        Unirse
                    </a>
                @endif
                <a href="{{ route('portal.reuniones') }}" class="meeting-more">Ver todas</a>
            </p>
        </article>
    @endif

    @if ($sections)
        <ul class="dashboard-cards">
            @foreach ($sections as $section)
                <li>
                    <a href="{{ route($section->routeName()) }}" class="dashboard-card">
                        <span class="dashboard-card-icon" aria-hidden="true">
                            <x-dynamic-component :component="'tabler-'.$section->icon()" />
                        </span>
                        <b>{{ $section->label() }}</b>
                        <span class="dashboard-card-note">{{ $section->description() }}</span>

                        @unless ($user->canWrite($section))
                            <span class="dashboard-tag">Sólo lectura</span>
                        @endunless
                    </a>
                </li>
            @endforeach
        </ul>
    @else
        {{-- A member invited with every box unticked. Better to say so plainly
             than to show an empty grid that looks like a loading failure. --}}
        <p class="dashboard-empty">
            Todavía no tienes acceso a ninguna sección. Pídele a quien administra
            la cuenta de {{ $user->activeBrand()?->name ?? 'tu marca' }} que te dé permisos.
        </p>
    @endif

</x-layouts.portal>
