{{--
    The brand's own Brand Egg. Read-only, and only ever reached once approved —
    the controller 404s otherwise.

    ⚠️ THE SAME COMPONENT AS THE ADMIN'S, WITHOUT `editable`. Not a second copy:
    the drawing, the rings and the state colours are identical, and a copy would
    drift the moment a ring changed shape. What differs is that nothing here
    composes, edits or approves — this side of the portal reads.

    ⚠️ brand-egg.css IS A COMPONENT STYLESHEET AND IS NAMED BY BOTH SHELLS.
    It may only use tokens both `.admin` and the portal define. That is exactly
    how permissions.css shipped broken once, on --box-edge, which only the
    portal had (CLAUDE.md §9).
--}}
<x-layouts.portal title="Brand Egg" :section="$section"
                  :css="['brand-egg', 'portal-brand-egg']"
                  :scripts="['resources/js/brand-egg.js']">

    <header class="dashboard-head">
        <h1>Brand Egg</h1>
        <p>
            Tu marca contada en cinco capas, de dentro hacia fuera. Es el
            resumen del que parte todo lo demás.
        </p>
    </header>

    <div class="portal-egg">

        <div class="portal-egg-stage">
            {{-- No `editable`: the rings still light and select, because a ring
                 is a control on both sides, but nothing here writes. --}}
            <x-brand-egg :egg="$egg->layerTexts()" data-brand-egg />
        </div>

        <div class="portal-egg-layers">
            @foreach ($layers as $layer)
                @php $text = $egg->text($layer); @endphp

                {{-- ⚠️ AN UNCOMPOSED LAYER IS ABSENT, NOT SHOWN AS A GAP. The
                     same rule the rest of this side follows: a client does not
                     need a list of what they have not been given yet, and
                     "esta capa está vacía" reads as Breakfast's unfinished
                     homework (ERR-07). The ring says it instead, by being
                     hollow, which is a fact about the drawing rather than a
                     sentence about the work. --}}
                @if ($text !== '')
                    <section class="portal-egg-layer"
                             data-brand-egg-card
                             data-layer="{{ $layer->value }}">
                        <h2 class="portal-egg-layer-title">
                            <span class="portal-egg-layer-ring" aria-hidden="true">{{ $layer->ring() }}</span>
                            {{ $layer->label() }}
                        </h2>

                        {{-- What the layer is FOR, before what it says about
                             this brand. Straight from the enum, so the client's
                             reading of a layer and Breakfast's are the same
                             words — see BrandEggLayer::description(). --}}
                        <p class="portal-egg-layer-purpose">{{ $layer->description() }}</p>

                        <p class="portal-egg-layer-text">{{ $text }}</p>
                    </section>
                @endif
            @endforeach
        </div>

    </div>

</x-layouts.portal>
