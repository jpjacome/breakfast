{{--
    The conversation that co-creates a Brand Egg — step 1 of §1 of the brief.

    ⚠️ NOT <x-admin.assistant>. That one is the dashboard's: one thread per
    person across every brand, read-only, and it never writes anything. This is
    a thread PER BRAND that two Breakfast people share, and every card in it
    writes — an entregable, or a layer. Folding them into one component would
    mean one file holding two memory models and two write paths.

    Driven by resources/js/egg-assistant.js, which posts to
    admin.clients.egg.assistant and gets back {reply, layer, checklist}.

    Props:
      client  the brand
      layers  BrandEggLayer::cases(), so the ring picker matches the drawing
--}}
@props(['client', 'layers'])

@php
    /*
     * Which entregables each section may write, as the page's own answer.
     *
     * ⚠️ THE MODEL IS NOT ASKED WHICH SECTION IT IS IN, and it is not trusted
     * with the key either. It produced `seccion=publico` — not a section at all
     * — and `item=publico_objetivo` for a column called `publicos`, and each
     * invention is a 404 the person reads as "no se pudo guardar". The ring
     * picker already knows the section, and this map already knows the keys, so
     * neither has to be guessed.
     */
    $layerItems = collect($layers)
        ->mapWithKeys(fn ($layer) => [
            $layer->value => collect($layer->sources())->map->value->all(),
        ]);

    /*
     * The thread, oldest first.
     *
     * ⚠️ NOT SCOPED TO A LAYER HERE, unlike what the model is sent. She reads
     * one ring at a time so layer 5's questions do not bury layer 1's; a person
     * scrolling back wants the whole conversation they had. The two differ on
     * purpose, and the turns carry their layer so the screen can say which was
     * which.
     */
    $history = App\Models\BrandEggMessage::query()
        ->where('client_id', $client->id)
        ->orderBy('id')
        ->get();
@endphp

<section class="egg-assistant"
         data-egg-assistant
         data-endpoint="{{ route('admin.clients.egg.assistant', $client) }}"
         {{-- ⚠️ Placeholders, not a URL built in JS. The route name stays in
              routes/web.php and the script substitutes two segments — a URL
              written into a script is a route duplicated outside the router. --}}
         data-save-endpoint="{{ route('admin.clients.deliverable.update', [$client, '__LAYER__', '__ITEM__']) }}"
         {{-- Where "guardar en el Brand Egg" actually lands. It used to only
              FILL the layer's textarea and leave somebody to press Guardar —
              but the button says it saves, so it saves. --}}
         data-layer-endpoint="{{ route('admin.clients.egg.update', [$client, '__LAYER__']) }}"
         data-layer-items="{{ json_encode($layerItems, JSON_UNESCAPED_UNICODE) }}">

    <header class="egg-assistant-head">
        <h3 class="admin-heading">construir el huevo</h3>
        <p class="admin-hint">
            Brandy pregunta, tú respondes, y lo que aceptes se guarda donde diga
            cada tarjeta. Nada se guarda solo.
        </p>
    </header>

    {{--
        Which ring is being worked on.

        ⚠️ A radio group, not a dropdown: five is few enough to show, and the
        current one has to be readable at a glance while typing — this is the
        thing that decides where the answer gets saved.
    --}}
    <div class="egg-assistant-rings" role="radiogroup" aria-label="Capa">
        @foreach ($layers as $ring)
            <label class="egg-assistant-ring">
                <input type="radio" name="egg-layer" value="{{ $ring->value }}"
                       @checked($loop->first) data-egg-layer>
                <span>{{ $ring->ring() }} · {{ $ring->label() }}</span>
            </label>
        @endforeach
    </div>

    {{-- The checklist, rendered by the server on load and replaced from the
         JSON of every turn. ⚠️ Never written by the model — see LayerProgress. --}}
    <div class="egg-assistant-checklist" data-egg-checklist aria-live="polite"></div>

    <div class="egg-assistant-thread" data-egg-thread role="log" aria-live="polite"
         aria-label="Conversación del Brand Egg">
        @foreach ($history as $turn)
            {{-- data-own: this thread belongs to the BRAND — the whole team
                 builds an Egg — and the composer's Up key recalls what YOU
                 sent, so the markup has to say which turns those are. --}}
            <div class="egg-turn egg-turn-{{ $turn->isFromAssistant() ? 'assistant' : 'user' }}"
                 @if ($turn->user_id === auth()->id()) data-own @endif>
                @if ($turn->layer)
                    <span class="egg-turn-layer">capa {{ $turn->layer->ring() }}</span>
                @endif
                <span data-egg-message>{{ $turn->body }}</span>
            </div>
        @endforeach
    </div>

    {{-- Whatever is going with the next message. Empty until something is
         pasted, dropped or picked. Same hook the other three panels use. --}}
    <div class="assistant-attached" data-assistant-attached></div>

    {{--
        ⚠️ THE SAME COMPOSER AS EVERY OTHER PANEL, not a second one.

        It was written from scratch here and came out looking like a browser
        default beside three panels that look alike — and without the paperclip
        there was no way to attach anything at all. The classes are
        assistant.css's; the behaviour is assistant-composer.js's.
    --}}
    <form class="assistant-composer" data-egg-form>
        <div class="assistant-ask">
            <label class="screen-reader-only" for="egg-message">Mensaje</label>

            {{-- A label wrapping a hidden input: a styled button that still
                 opens the file dialog with no JavaScript to make it do so. --}}
            <label class="assistant-tool" title="Adjuntar una imagen">
                <span class="screen-reader-only">Adjuntar archivo</span>
                <x-tabler-paperclip aria-hidden="true" />
                <input type="file" data-assistant-file hidden multiple
                       accept="image/png,image/jpeg,image/webp,image/gif,application/pdf">
            </label>

            <textarea id="egg-message" name="message" rows="1" autocomplete="off"
                      class="assistant-input"
                      placeholder="Responde, pega una imagen, o pregunta por dónde empezar…"></textarea>

            <button type="submit" class="admin-button admin-button-secondary assistant-send">
                <span class="screen-reader-only">Enviar</span>
                <x-tabler-arrow-up aria-hidden="true" />
            </button>
        </div>
    </form>

</section>
