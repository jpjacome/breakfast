@php
    /*
     * The Brand Egg, for Breakfast.
     *
     * ⚠️ THIS WAS A BENCH UNTIL THE BACK END LANDED. The layout, the CSS, the
     * JS and the drawing were built first against hand-written texts; what
     * changed here is only where the words come from — $egg, a real BrandEgg
     * row — and the three writes that were missing: compose, accept, approve.
     *
     * ⚠️ A LAYER HAS TWO STATES, NOT FOUR. Composed or not. Sin generar, sin
     * aprobar, aprobado and desactualizado are the EGG's, asked once per brand
     * (docs/brand-egg.md §5), so they are printed once beside the drawing
     * rather than on each of the five cards.
     *
     * Layer 4 will usually be the hollow one: docs/brand-egg.md §11 step 10 is
     * the only part of that plan gated on work outside it, and until the image
     * work lands it composes from Look and feel and Relato alone.
     */

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

    $texts = $egg->layerTexts();
@endphp

<x-layouts.app
    :title="$client->name.' · Brand Egg'"
    :css="['brand-egg', 'admin-brand-egg', 'egg-assistant']"
    :scripts="[
        'resources/js/brand-egg.js',
        'resources/js/egg-assistant.js',
        'resources/js/brand-egg-split.js',
    ]"
    :heading="$heading"
>

    {{--
        TWO ROWS, AND THE BOTTOM ONE IS TWO COLUMNS.

            ┌──────────────────────────────┐
            │  Brandy, building the egg    │  half the page
            ├───────────────┬──────────────┤
            │  the drawing  │  state and   │  half the page
            │  (still)      │  five layers │
            └───────────────┴──────────────┘

        ⚠️ THE CONVERSATION IS THE TOP HALF BECAUSE IT IS THE WORK. The five
        paragraphs are the OUTCOME, and they are read a layer at a time while
        looking at where that layer sits in the drawing — which is why the egg
        keeps its own column and never scrolls. Putting the panel in the aside
        (its first home) made the thing you spend the hour in the narrowest
        column on the screen.
    --}}
    <div class="brand-egg-work" data-egg-split-work>

        <div class="brand-egg-talk">
            <x-admin.egg-assistant :client="$client" :layers="$layers" />
        </div>

        {{--
            The line between the rows, and it moves.

            ⚠️ A CONTROL, SO IT TAKES THE KEYBOARD. Same rule the rings follow:
            a thing that only answers a mouse is not a control. role="separator"
            with tabindex, and brand-egg-split.js gives it the arrows, Home and
            End — plus a double-click to put it back in the middle.

            Outside the two-row media query it simply has nothing to separate,
            and the CSS hides it there rather than leaving a draggable line on a
            page that is one column.
        --}}
        <div class="brand-egg-split"
             data-egg-split
             role="separator"
             aria-orientation="horizontal"
             aria-label="Repartir el alto entre la conversación y las capas"
             aria-valuemin="0" aria-valuemax="100" aria-valuenow="50"
             tabindex="0"></div>

        <div class="brand-egg-screen">

        {{-- ------------------------------------------------------------
             The drawing. Held still: it is what the right column is about.
             ------------------------------------------------------------ --}}
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

                <x-brand-egg :egg="$texts" :state="$state" editable data-brand-egg />
            </section>

        </aside>

        {{-- ------------------------------------------------------------
             The five layers, as the composer wrote them
             ------------------------------------------------------------ --}}
        <div class="admin-stack brand-egg-read">

            {{-- ⚠️ THE STATE IS SAID ONCE, HERE, for the whole egg. It sits
                 under the drawing rather than on the cards because that is the
                 shape of the question: whether Breakfast has signed this brand
                 off is asked once, not five times. --}}
            {{-- ⚠️ THE ENDPOINT IS AN ATTRIBUTE, NOT A STRING IN THE SCRIPT.
                 brand-egg.js is one bundle shared with the client's read-only
                 egg, where this route does not exist and the person may not
                 post to it. A URL written into the script would be a route
                 name duplicated outside routes/web.php; read from the DOM, the
                 compose half simply finds nothing on the portal and does
                 nothing. --}}
            <section class="admin-card brand-egg-status"
                     data-brand-egg-endpoint="{{ route('admin.clients.egg.compose', $client) }}">
                <p class="brand-egg-status-line">
                    <span class="admin-badge {{ $state->badgeClass() }}">{{ $state->label() }}</span>
                </p>

                <p class="brand-egg-status-note">{{ $state->description() }}</p>

                @if ($egg->approved_at)
                    {{-- Already local time. CLAUDE.md §4: the database holds
                         Ecuador time, so a conversion here would read five
                         hours ahead of whoever approved it. --}}
                    <p class="brand-egg-status-note">
                        Aprobado el {{ $egg->approved_at->translatedFormat('j \d\e F \d\e Y') }}
                        @if ($egg->approver)
                            por {{ $egg->approver->name }}
                        @endif
                    </p>
                @endif

                <div class="brand-egg-status-actions">
                    {{-- Composing everything is for a brand's FIRST egg. The
                         per-ring button on each card is the normal path: a
                         brand whose Relato was rewritten needs layers 1 and 3
                         again, not five calls. --}}
                    <button type="button"
                            class="admin-button admin-button-outline admin-button-sm"
                            data-brand-egg-compose>
                        Componer todo
                    </button>

                    <form method="POST" action="{{ route('admin.clients.egg.approve', $client) }}">
                        @csrf
                        <button type="submit"
                                class="admin-button admin-button-primary admin-button-sm"
                                @disabled($egg->isEmpty())>
                            Aprobar
                        </button>
                    </form>
                </div>

                {{-- Where a compose run reports what it did, and what it could
                     not. Empty until something happens, so the card does not
                     carry a permanent blank row. --}}
                <p class="brand-egg-status-note" data-brand-egg-report hidden></p>
            </section>


            @foreach ($layers as $layer)
                @php
                    $text = $texts[$layer->value] ?? null;

                    /*
                     * Whether this layer could be composed at all: at least one
                     * of the entregables it reads has been written. Asked from
                     * the enum's own list, so the screen cannot disagree with
                     * the composer about what feeds a layer.
                     */
                    $hasSources = collect($layer->sources())
                        ->contains(fn ($item) => $deliverables->has($item));
                @endphp

                <section class="admin-card brand-egg-layer @if($text) is-composed @endif"
                         data-brand-egg-card
                         data-layer="{{ $layer->value }}">

                    <div class="brand-egg-layer-head">
                        <span class="brand-egg-layer-ring" aria-hidden="true">{{ $layer->ring() }}</span>

                        <div class="brand-egg-layer-heading">
                            <h3 class="brand-egg-layer-title">{{ $layer->label() }}</h3>
                            <p class="brand-egg-layer-purpose">{{ $layer->description() }}</p>
                        </div>

                        <button type="button"
                                class="admin-button admin-button-ghost admin-button-sm"
                                data-brand-egg-compose="{{ $layer->value }}"
                                @disabled(! $hasSources)>
                            {{ $text ? 'Recomponer' : 'Componer' }}
                        </button>
                    </div>

                    @if ($text)
                        <p class="brand-egg-layer-text" data-brand-egg-text>{{ $text }}</p>
                    @elseif ($hasSources)
                        <p class="brand-egg-layer-empty" data-brand-egg-text>
                            Esta capa todavía no se ha compuesto.
                        </p>
                    @else
                        {{-- ⚠️ NOT PHRASED AS BREAKFAST'S UNFINISHED HOMEWORK.
                             ERR-07 of the beta review: an empty source is a
                             fact about where the brand is, not a debt. --}}
                        <p class="brand-egg-layer-empty" data-brand-egg-text>
                            Ninguno de los entregables que lee esta capa tiene contenido todavía,
                            así que no hay nada que sintetizar.
                        </p>
                    @endif

                    {{-- ⚠️ A PERSON CAN ALWAYS REWRITE A LAYER, and this is the
                         only path other than a composition. Closed by default:
                         the screen is for READING the five paragraphs, and a
                         textarea open under each one would bury them. --}}
                    <details class="brand-egg-layer-edit">
                        <summary>Editar a mano</summary>

                        <form method="POST"
                              action="{{ route('admin.clients.egg.update', [$client, $layer->value]) }}"
                              class="admin-field">
                            @csrf
                            @method('PUT')

                            {{-- aria-label rather than a visually-hidden
                                 <label>: the summary above already names the
                                 layer on screen, and this codebase has no
                                 visually-hidden utility — adding one for a
                                 single field would be speculative CSS. --}}
                            {{-- data-brand-egg-layer-input is what a card from
                                 the assistant fills. ⚠️ It FILLS, it does not
                                 post: the person still presses Guardar on the
                                 layer they are about to change, which keeps the
                                 one path that writes a layer a person's. --}}
                            <textarea id="text-{{ $layer->value }}"
                                      name="text"
                                      data-brand-egg-layer-input="{{ $layer->value }}"
                                      aria-label="Texto de la capa {{ $layer->label() }}"
                                      rows="6"
                                      maxlength="5000"
                                      placeholder="Un solo párrafo, en el registro de la marca.">{{ $text }}</textarea>

                            <div class="brand-egg-layer-edit-actions">
                                <button type="submit" class="admin-button admin-button-outline admin-button-sm">
                                    Guardar capa
                                </button>
                                {{-- Vaciar is a real instruction, not a slip:
                                     it is how a ring goes back to hollow when
                                     its synthesis was wrong and its sources
                                     are not ready to be read again. --}}
                                <span class="brand-egg-layer-edit-note">
                                    Guardar en blanco vacía la capa.
                                </span>
                            </div>
                        </form>
                    </details>

<p class="brand-egg-layer-sources">
                        @if ($layer->isInventory())
                            {{-- No entregable feeds this layer, on purpose: it IS the
                                 brand's list of assets rather than a reading of one.
                                 See BrandEggLayer::sources(). --}}
                            <b>No se sintetiza:</b> es el inventario de archivos de la marca.
                            Cada archivo entra con su tipo y su descripción.
                        @else
                            <b>Se sintetiza de:</b>
                            {{ collect($layer->sources())->map(fn ($item) => $item->label())->implode(' · ') }}
                            @if ($layer->dependsOn())
                                — y del resultado de «{{ $layer->dependsOn()->label() }}», que por eso
                                se compone antes.
                            @endif
                        @endif
                    </p>

                </section>
            @endforeach

        </div>
        </div>

    </div>

</x-layouts.app>
