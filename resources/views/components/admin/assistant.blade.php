{{--
    The Breakfast assistant, as it appears anywhere in /admin.

    ONE AGENT, ONE FACE. This is the same assistant on every admin screen that
    carries it — the dashboard, the new-brand form — reading the state of every
    brand from the database. Two different-looking agents in one back office is
    two agents as far as anyone using it is concerned.

    Driven by resources/js/assistant.js, which posts to admin.assistant. It is
    READ-ONLY: it answers questions and points you at the screen where a thing
    is done. It never does the thing.

    Props:
      brands    the roster for the picker, already scoped to this user
      greeting  the line above the orb
      compact   true where the assistant shares a screen with a form and must
                not become the page
--}}
@props([
    'brands',
    'greeting' => null,
    'intro' => null,
    'compact' => false,
])

@php
    // The turns Brandy actually remembers, oldest first.
    //
    // ⚠️ THE SAME NUMBER SHE READS, deliberately. AssistantMessage::thread()
    // caps what goes into the prompt; showing more here would let somebody
    // scroll back to a question she can no longer see and expect her to follow
    // it. The transcript on screen IS her memory, not a longer archive of it.
    $history = App\Models\AssistantMessage::query()
        ->thread(auth()->id(), App\Models\AssistantMessage::SURFACE_ADMIN)
        ->get()
        ->reverse()
        ->values();
@endphp

<section class="assistant {{ $compact ? 'assistant-compact' : '' }}"
         data-assistant
         data-endpoint="{{ route('admin.assistant') }}">

    <h2 class="assistant-greeting">
        {{ $greeting ?? 'Hola, '.Str::of(auth()->user()->name)->explode(' ')->first().'.' }}
    </h2>

    {{-- Brandy says who she is. Same shape as the client's dashboard: the
         greeting is the heading, the introduction is a sentence under it — a
         whole sentence set as a display heading reads as a banner. --}}
    @if ($intro)
        <p class="assistant-intro">{{ $intro }}</p>
    @endif

    <div class="assistant-orb">
        <x-assistant-orb />
    </div>

    <div class="assistant-thread" data-assistant-thread role="log" aria-live="polite"
         aria-label="Conversación con el asistente">
        {{-- Rendered server-side so the conversation is there on load rather
             than after a round trip. assistant.js picks these up on boot and
             runs the same route-linkifier over them that a live reply gets. --}}
        @foreach ($history as $turn)
            <div class="assistant-message assistant-message-{{ $turn->isFromAssistant() ? 'assistant' : 'user' }}">
                <span data-assistant-message>{{ $turn->body }}</span>
                <x-turn-attachments :names="$turn->attachments" :ids="$turn->attachment_ids" />
            </div>
        @endforeach
    </div>

    {{-- Whatever is going with the next message. Empty until something is
         pasted, dropped, picked or recorded. --}}
    <div class="assistant-attached" data-assistant-attached></div>

    <form class="assistant-composer" data-assistant-form>

        {{--
            General is the default and the first option. Most of what the
            Breakfast side asks cuts across the whole roster — which brands are
            stuck, what is on this week — and forcing a brand to be picked
            before you can type would make the common question the awkward one.
            The picker narrows; it does not gate.
        --}}
        <div class="assistant-ask">
            <label class="screen-reader-only" for="assistant-question">Pregunta</label>
            {{-- Paste an image, drop one, pick one, or record a voice note.
                 The picker is a label wrapping a hidden input: a styled button
                 that still opens the file dialog without any JavaScript to
                 make it do so. --}}
            <label class="assistant-tool" title="Adjuntar una imagen">
                <span class="screen-reader-only">Adjuntar archivo</span>
                <x-tabler-paperclip aria-hidden="true" />
                <input type="file" data-assistant-file hidden
                       accept="image/png,image/jpeg,image/webp,image/gif,application/pdf">
            </label>

            {{-- See the portal's copy of this panel: a textarea so a long
                 question can be read before sending, with the Enter rules in
                 assistant-composer.js. --}}
            <textarea id="assistant-question" name="question" rows="1" autocomplete="off"
                      class="assistant-input"
                      placeholder="Pregunta lo que quieras…"></textarea>

            <button type="button" class="assistant-tool" data-assistant-record
                    aria-pressed="false" title="Grabar una nota de voz">
                <span class="screen-reader-only">Grabar una nota de voz</span>
                <x-tabler-microphone aria-hidden="true" />
            </button>

            {{-- The arrow carries the meaning everywhere else a message is
                 sent, so the word is only there for screen readers. --}}
            <button type="submit" class="admin-button admin-button-secondary assistant-send">
                <span class="screen-reader-only">Preguntar</span>
                <x-tabler-arrow-up aria-hidden="true" />
            </button>
        </div>

        {{-- Under the field: the default answers for itself, and most questions
             never touch it. Narrowing to one brand is the exception, so it sits
             where an exception belongs. --}}
        <label class="screen-reader-only" for="assistant-brand">Marca</label>
        <select id="assistant-brand" name="brand" class="assistant-brand">
            <option value="">Todas las marcas</option>
            @foreach($brands as $brand)
                <option value="{{ $brand->slug }}">{{ $brand->name }}</option>
            @endforeach
        </select>
    </form>

</section>
