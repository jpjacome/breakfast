@php
    use App\Enums\BrandEggLayer;

    /*
     * The Brand Egg's map — which entregable feeds which layer.
     *
     * ⚠️ NOTHING IS TRANSCRIBED HERE. Every layer, every source, every label and
     * every obligatorio flag is read from BrandEggLayer and DeliverableItem as
     * the page renders. Type an entregable's name into this file and you have
     * created a second vocabulary that will disagree with sources() the first
     * time somebody edits it — which is exactly what the week-one HTML in docs/
     * did, and why CLAUDE.md trap 7 says not to trust it.
     *
     * ⚠️ THIS IS A PUBLIC PAGE, SO IT WEARS THE SITE'S CLOTHES. <x-layouts.app>
     * dereferences auth()->user() for the rail, which is a 500 for a guest, and
     * every admin-* class it styles lives in admin.css, which a public page
     * never loads. Both halves of that trap are why this screen owns its own
     * stylesheet and uses none of the back office's classes — see
     * brand-egg-map.css, which also has to declare the tokens the drawing
     * needs, because those come from admin.css too.
     *
     * :noindex is what "unlisted" means in practice: reachable with the URL,
     * absent from search. Nothing in the site links here.
     *
     * ⚠️ THE DRAWING IS <x-brand-egg>, NOT A COPY OF IT. The same component the
     * admin bench renders, so a ring that changes shape changes here too. What
     * this page adds is $hrefs: each ring becomes a real <a>, and the egg IS
     * the index — the one thing a picture of it could never be.
     *
     * ⚠️ NO RING IS HATCHED ANY MORE. Ring 4 was drawn as pending while
     * "Brand Assets" was an open question — it was the only layer whose
     * sources nobody had settled. It was settled on 2026-09-16: the layer
     * reads the nine VISUAL ENTREGABLES, and the brand's image files reach it
     * as TEXT, through the reading DescribeBrandAsset stores on each row.
     *
     * $pending stays as a null so the branches below read as "is this layer
     * the unsettled one" rather than being deleted outright — the next open
     * question gets named here instead of re-derived. No brand's data is
     * involved on this screen at all.
     */
    $pending = null;

    $drawing = collect(BrandEggLayer::cases())
        ->mapWithKeys(fn (BrandEggLayer $layer) => [
            $layer->value => $layer === $pending ? null : $layer->label(),
        ])
        ->all();

    // Built from ring(), the same number the section ids are built from, so an
    // anchor cannot drift from the heading it points at.
    $hrefs = collect(BrandEggLayer::cases())
        ->mapWithKeys(fn (BrandEggLayer $layer) => [$layer->value => '#capa-'.$layer->ring()])
        ->all();
@endphp

<x-layouts.site-remake
    title="Brand Egg"
    description="Las cinco capas del Brand Egg y de qué entregables se compone cada una."
    :css="['brand-egg', 'brand-egg-map']"
    :noindex="true"
>

<div class="egg-map">
    <div class="egg-map-inner">

        <header class="egg-map-head">
            <p class="egg-map-eyebrow">Breakfast · The Brand Therapist</p>
            <h1 class="egg-map-display">Las cinco capas del Brand Egg</h1>
            <p class="egg-map-lead">
                Cada capa es una lectura derivada: al modelo se le pide <b>la relación</b>
                entre sus entregables, escrita como un solo párrafo. No es un resumen ni una
                lista, y no puede decir nada que no esté en el texto que recibió.
            </p>
        </header>

        <div class="egg-map-top">

            <div class="egg-map-plate">
                <img class="egg-map-skillet"
                     src="{{ asset('img/skillet.png') }}"
                     alt="" aria-hidden="true"
                     width="1050" height="1050">

                <x-brand-egg :egg="$drawing" :hrefs="$hrefs" />
            </div>

            <nav class="egg-map-toc" aria-label="Las cinco capas">
                <p class="egg-map-eyebrow">Haz click en un anillo</p>
                <ol>
                    @foreach ($layers as $layer)
                        <li @class(['is-pending' => $layer === $pending])>
                            <a href="#capa-{{ $layer->ring() }}">
                                <b>{{ $layer->ring() }}</b>
                                <span>{{ $layer->label() }}</span>
                            </a>
                        </li>
                    @endforeach
                </ol>
                <p class="egg-map-hint">
                    La yema es la esencia y cada anillo la envuelve. El cuarto está rayado
                    porque sus insumos son la decisión que falta.
                </p>
            </nav>

            <dl class="egg-map-figures">
                <div>
                    <dt>{{ count($layers) }}</dt>
                    <dd>capas, sintetizadas de los entregables — nunca del toolkit</dd>
                </div>
                <div>
                    <dt>{{ $assignedCount }}</dt>
                    <dd>entregables distintos alimentan alguna capa</dd>
                </div>
                <div class="flag">
                    <dt>{{ $totalCount - $assignedCount }}</dt>
                    <dd>de los {{ $totalCount }} no alimentan ninguna</dd>
                </div>
            </dl>
        </div>

        {{-- ------------------------------------------------------------
             The five layers
             ------------------------------------------------------------ --}}
        @foreach ($layers as $layer)
            <article class="egg-map-layer @if($layer === $pending) is-pending @endif"
                     id="capa-{{ $layer->ring() }}">

                <div class="egg-map-ring" aria-hidden="true">{{ $layer->ring() }}</div>

                <div class="egg-map-layer-head">
                    <h2 class="egg-map-layer-title">{{ $layer->label() }}</h2>
                    <p class="egg-map-hint">{{ $layer->description() }}</p>
                </div>

                <div class="egg-map-sources">
                    @if ($layer->dependsOn())
                        <p class="egg-map-source derived">
                            <span>{{ $layer->dependsOn()->label() }} — el resultado de la capa
                                {{ $layer->dependsOn()->ring() }}, no un entregable</span>
                            <span class="egg-map-col">capa {{ $layer->dependsOn()->ring() }}</span>
                            <span class="egg-map-badge open">por confirmar</span>
                        </p>
                    @endif

                    @foreach ($layer->sources() as $item)
                        <p class="egg-map-source">
                            <span>{{ $item->label() }}</span>
                            {{-- The case value IS the column in brand_deliverables.
                                 Printed because on this screen that identity is the
                                 point: one vocabulary, no mapping table. --}}
                            <span class="egg-map-col">{{ $item->value }}</span>
                            <span class="egg-map-badge {{ $item->isRequired() ? 'req' : '' }}">
                                {{ $item->isRequired() ? 'obligatorio' : 'opcional' }}
                            </span>
                        </p>
                    @endforeach

                    @if ($layer->isInventory())
                        <p class="egg-map-source">
                            <span><b>Los archivos de la marca</b></span>
                            {{-- The layer IS this list. It reads no entregable at
                                 all, which is why nothing is printed above. --}}
                            <span class="egg-map-col">brand_egg_assets</span>
                            <span class="egg-map-badge req">inventario</span>
                        </p>
                        <p class="egg-map-source">
                            <span><b>Cómo se ven los archivos de la marca</b></span>
                            {{-- Not an entregable and not a column on
                                 brand_deliverables — it is a field on each file's
                                 own row, which is why it prints its table. --}}
                            <span class="egg-map-col">brand_assets.visual_reading</span>
                            <span class="egg-map-badge">texto</span>
                        </p>
                    @endif
                </div>

                @if ($layer->isInventory())
                    <p class="egg-map-note">
Esta capa no se sintetiza de ningún entregable: <b>es</b> la lista de archivos de
                        la marca, elegidos uno a uno. Y lee <b>cómo se ven</b>. No las imágenes:
                        el texto. Cada imagen que se archiva se describe una vez y esa descripción
                        se guarda junto al archivo, así que la capa recibe palabras como cualquier
                        otra, y el Brand Egg nunca mira una foto.
                    </p>
                @endif
            </article>
        @endforeach

        {{-- ------------------------------------------------------------
             What feeds nothing
             ------------------------------------------------------------ --}}
        <section class="egg-map-rest">
            <h2 class="egg-map-title">
                Los {{ $totalCount - $assignedCount }} que no alimentan ninguna capa
            </h2>
            <p class="egg-map-lead">
                No es necesariamente un error: el Egg es una síntesis de lo esencial, no un
                índice de los {{ $totalCount }}. Pero conviene mirarlo una vez, porque hay
                trabajo obligatorio aquí que hoy no llega a la memoria principal de la marca.
            </p>

            <div class="egg-map-groups">
                @foreach ($groups as $name => $items)
                    <div class="egg-map-group @if($name === $candidateGroup) is-candidate @endif">
                        <h3>{{ $name }}</h3>
                        <p class="egg-map-count">{{ count($items) }}</p>
                        <ul>
                            @foreach ($items as $item)
                                <li>
                                    <span>{{ $item->label() }}</span>
                                    <span class="egg-map-col">{{ $item->isRequired() ? 'oblig' : 'opc' }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($name === $candidateGroup)
                            <p class="egg-map-hint">
                                Este grupo era el que decidía la capa 4, y ya está decidido: casi
                                todos pasaron a alimentarla el 2026-09-16. Lo que queda aquí no es
                                identidad visual que falte leer, sino lo que no lo es.
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

    </div>
</div>

</x-layouts.site-remake>
