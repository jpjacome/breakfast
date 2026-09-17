{{--
    The brand, as it has been written so far.

    Every entregable with content, in board order. Nothing else — an empty one
    is absent, not a gap. A client does not need a list of what they have not
    been given yet; that list is the admin's board and the assistant's prompt,
    where absence has to be stated for different reasons entirely.
--}}
{{-- Title and heading both from the enum: the sidebar said "Estrategia" while
     this page said "Tu marca" for weeks, because they were two strings. --}}
<x-layouts.portal :title="$section->label()" :section="$section"
                  css="checklist" :scripts="['resources/js/checklist.js']">

    <header class="dashboard-head">
        <h1>{{ $section->label() }}</h1>
        <p>
            Todo lo que hemos definido, en un solo lugar. Esta página se va
            escribiendo sola conforme avanza el trabajo.
        </p>
    </header>

    {{-- SEG-04. Above the entregables rather than among them: it is a fact
         about the brand, not something Breakfast writes as part of the work,
         and it is only printed once somebody has actually answered. --}}
    @if ($client->trademark_registered !== null)
        <p class="brand-fact">
            <strong>Marca registrada:</strong> {{ $client->trademarkLabel() }}
        </p>
    @endif

    {{-- The Brand Egg. ALWAYS here, approved or not.

         ⚠️ A LINK RATHER THAN THE DRAWING INLINE. The egg is the brand told
         whole, in five paragraphs; dropped at the top of this page it would
         push the entregables — which is what people come here for — below the
         fold, and say the same things twice on one screen.

         ⚠️ IT USED TO BE HIDDEN UNTIL APPROVAL, and that changed 2026-09-17 at
         Breakfast's request: a brand had no idea the Brand Egg was part of what
         they were getting until the day it appeared. What approval gates is the
         TEXT of each layer, on the page itself — not whether the brand knows
         the thing exists.

         ⚠️ The second line never reads as a debt. ERR-07: "todavía no está
         aprobado" is a sentence about Breakfast's internal work said to the
         wrong audience. --}}
    <a class="brand-egg-entry" href="{{ route('portal.estrategia.egg') }}">
        <span class="brand-egg-entry-title">Brand Egg</span>
        <span class="brand-egg-entry-note">
            @if ($client->brandEggState()->isVisibleToClient())
                Tu marca en cinco capas, de dentro hacia fuera.
            @else
                Tu marca en cinco capas. Todavía se está cocinando.
            @endif
        </span>
    </a>

    {{-- SEG-05. Above the entregables: it is the one thing on this page the
         client can act on, and burying it under forty of their own brand
         attributes is where it would never be seen. --}}
    <x-implementation-checklist
        :checklist="$checklist"
        :ticks="$ticks"
        editable
        :action="route('portal.estrategia.checklist')" />

    @if ($written === [] && $checklist->isEmpty())
        {{-- Said plainly rather than left as an empty page, which reads as
             something that failed to load. --}}
        <p class="dashboard-empty">
            Todavía no hay nada escrito. Conforme tu equipo de Breakfast defina
            la marca, va a aparecer aquí.
        </p>
    @else
        {{-- No counter here, deliberately. "25 de 48" told the client how much
             of their brand is missing, in a number they cannot act on and
             against a denominator that means nothing to them — 28 of the 48 are
             optional and most brands never get all of them, so the fraction
             reads as a project three-quarters done for a brand that is
             finished. The page is what HAS been written; the count of what has
             not belongs to the admin's board. --}}
        <div class="brand-page">
            @foreach ($written as $item)
                <article class="brand-entry" id="{{ $item->value }}">
                    <h2>{{ $item->label() }}</h2>
                    <div class="brand-entry-body">
                        <x-linked-text :text="$deliverables->value($item)" />
                    </div>
                </article>
            @endforeach
        </div>
    @endif

</x-layouts.portal>
