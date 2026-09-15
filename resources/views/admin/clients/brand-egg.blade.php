@php
    use App\Enums\BrandEggLayer;

    /*
     * ⚠️ A BENCH, NOT THE SCREEN. The texts below are hand-written so the
     * drawing has something to say while the back end is built. The real
     * screen reads them from brand_eggs, and regeneration is per RING rather
     * than per egg (docs/brand-egg.md §3): a brand whose Relato was rewritten
     * needs layers 1 and 3 again, not five calls.
     *
     * The layout, the CSS, the JS and the drawing are the real ones. Only the
     * content is stand-in, and it says so in every paragraph.
     *
     * ⚠️ A LAYER HAS TWO STATES, NOT THREE. Composed or not. Sin generar,
     * sin aprobar, aprobado and desactualizado are the EGG's, asked once per
     * brand (docs/brand-egg.md §5), and they are printed once beside the
     * drawing rather than per card.
     *
     * Layer 4 is benched empty deliberately: docs/brand-egg.md §11 step 10 is
     * the only part of that plan gated on work outside it, and it ships as an
     * outline ring until the image work lands. A screen benched with five
     * identical cards is a screen whose empty state was never looked at.
     */
    $benchText = fn (BrandEggLayer $layer) => 'Texto de ejemplo para «'.$layer->label().'». '
        .'Esta capa se compone a partir de '.count($layer->sources()).' entregables'
        .($layer->dependsOn() ? ' y del resultado de «'.$layer->dependsOn()->label().'»' : '')
        .', y se escribe como un solo párrafo continuo: no es una lista de sus fuentes, '
        .'sino la relación entre ellas dicha de una vez.';

    /*
     * The topbar line: the brand, then this screen's name in bold.
     *
     * ⚠️ AN HtmlString BECAUSE THE LAYOUT ESCAPES $heading, and it should —
     * every other screen passes a bare word ("proceso", "marca"). Blade's e()
     * hands back an Htmlable untouched, which is the one sanctioned way past
     * that, so the brand's own name is escaped HERE, explicitly, rather than
     * being trusted. A client is named by whoever typed it into the ficha.
     */
    $heading = new Illuminate\Support\HtmlString(
        e($client->name).' · <b>Brand Egg</b>'
    );

    $bench = [
        BrandEggLayer::Esencia->value      => $benchText(BrandEggLayer::Esencia),
        BrandEggLayer::Personalidad->value => $benchText(BrandEggLayer::Personalidad),
        BrandEggLayer::Beneficios->value   => $benchText(BrandEggLayer::Beneficios),
        BrandEggLayer::Assets->value       => null,
        BrandEggLayer::Universo->value     => $benchText(BrandEggLayer::Universo),
    ];
@endphp

<x-layouts.app
    :title="$client->name.' · Brand Egg'"
    :css="['brand-egg', 'admin-brand-egg']"
    :scripts="['resources/js/brand-egg.js']"
    :heading="$heading"
>

    {{-- ----------------------------------------------------------------
         The five layers, as a row

         ⚠️ THIS IS WHERE THE PAGE TITLE USED TO BE, and that is the point:
         the screen's name moved up into the topbar beside the brand's, and
         the row it vacated buys the drawing its height back. A heading that
         repeats what the rail and the topbar already say is the cheapest
         thing on the page to give up.

         It is the THIRD face of the same five layers — the rings, the cards
         and this — so it carries no state and no description. Those are said
         on the card, once. What it adds is the one thing neither of the others
         gives you: every layer's name visible at once, in order, without
         scrolling to find out what the fourth one is called.

         No JavaScript of its own: brand-egg.js lights and selects anything
         carrying data-layer outside the drawing, so a button here behaves
         exactly as its ring does, and adding a fourth face would need no
         change either.
         ---------------------------------------------------------------- --}}
    <nav class="brand-egg-nav" aria-label="Las cinco capas">
        @foreach ($layers as $layer)
            <button type="button"
                    class="brand-egg-nav-item"
                    data-brand-egg-nav
                    data-layer="{{ $layer->value }}">
                <span class="brand-egg-nav-ring" aria-hidden="true">{{ $layer->ring() }}</span>
                {{ $layer->label() }}
            </button>
        @endforeach
    </nav>

    <div class="brand-egg-screen">

        {{-- ------------------------------------------------------------
             The drawing
             ------------------------------------------------------------

             ⚠️ THE DRAWING AND NOTHING ELSE. There was a panel here that named
             the hovered ring and listed its entregables, and it said exactly
             what that layer's card on the right already says — the same label,
             the same description, the same sources, twice on one screen. It
             also cost the egg its height, which is the one thing this pane is
             for. The ring still answers: hovering it lights its card, and
             clicking it brings that card into view. --}}
        <aside class="brand-egg-stage">
            <section class="admin-card">

                {{-- Scenery, and nothing else: alt="" and aria-hidden, because
                     "a skillet" is not information about the brand and a screen
                     reader announcing one would be noise between the rings.
                     pointer-events are off in the CSS for the same reason — it
                     sits under the egg and must never intercept a ring. --}}
                <img class="brand-egg-skillet"
                     src="{{ asset('img/skillet.png') }}"
                     alt="" aria-hidden="true"
                     width="1050" height="1050">

                <x-brand-egg :egg="$bench" state="sin_aprobar" editable data-brand-egg />
            </section>
        </aside>

        {{-- ------------------------------------------------------------
             The five layers, as the composer will eventually write them
             ------------------------------------------------------------ --}}
        <div class="admin-stack">

            @foreach ($layers as $layer)
                @php $text = $bench[$layer->value] ?? null; @endphp

                <section class="admin-card brand-egg-layer @if($text) is-composed @endif"
                         data-brand-egg-card
                         data-layer="{{ $layer->value }}">

                    <div class="brand-egg-layer-head">
                        <span class="brand-egg-layer-ring" aria-hidden="true">{{ $layer->ring() }}</span>

                        <div class="brand-egg-layer-heading">
                            <h3 class="brand-egg-layer-title">{{ $layer->label() }}</h3>
                            <p class="brand-egg-layer-purpose">{{ $layer->description() }}</p>
                        </div>
                    </div>

                    @if ($text)
                        <p class="brand-egg-layer-text">{{ $text }}</p>
                    @else
                        <p class="brand-egg-layer-empty">
                            Esta capa todavía no se ha compuesto.
                        </p>
                    @endif

                    <p class="brand-egg-layer-sources">
                        <b>Se sintetiza de:</b>
                        {{ collect($layer->sources())->map(fn ($item) => $item->label())->implode(' · ') }}
                        @if ($layer->dependsOn())
                            — y del resultado de «{{ $layer->dependsOn()->label() }}», que por eso
                            se compone antes.
                        @endif
                    </p>

                </section>
            @endforeach

        </div>
    </div>

</x-layouts.app>
