{{--
    The brand's assistant, on the client's dashboard.

    THE SAME PANEL THE BREAKFAST SIDE HAS — same markup, same assistant.js,
    same assistant.css — pointed at a different endpoint. One agent with two
    faces would be two agents to anyone using it.

    ⚠️ NO BRAND PICKER HERE, and that is the whole difference. Since ACC-01 an
    account CAN be in several brands — but the choice is made once, in the
    sidebar, and this panel simply answers about whichever brand you are in
    (User::activeBrand()). So there is still no brand to pick inside the
    conversation and nothing that could be chosen wrongly. The admin's copy has
    a picker because the Breakfast side crosses brands within one thread, which
    is precisely what this side is forbidden to do.

    Read-only, like every other page on this side.
--}}
@props(['greeting' => null])

@php
    // The turns Brandy actually remembers, oldest first.
    //
    // ⚠️ THE SAME NUMBER SHE READS, deliberately. AssistantMessage::thread()
    // caps what goes into the prompt; showing more here would let somebody
    // scroll back to a question she can no longer see and expect her to follow
    // it. The transcript on screen IS her memory, not a longer archive of it.
    // ⚠️ Scoped to the brand being looked at, like the prompt is: an account
    // can be in several (ACC-01), and a transcript that showed all of them
    // would show turns Brandy cannot see from here.
    $history = App\Models\AssistantMessage::query()
        ->thread(
            auth()->id(),
            App\Models\AssistantMessage::SURFACE_PORTAL,
            clientId: auth()->user()->activeBrand()?->id,
        )
        ->get()
        ->reverse()
        ->values();
@endphp

<section class="assistant"
         data-assistant
         data-endpoint="{{ route('portal.assistant') }}">

    {{-- Omitted where the page already greets, which is the dashboard. --}}
    @if ($greeting)
        <h2 class="assistant-greeting">{{ $greeting }}</h2>
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

            {{-- A textarea, not an input: a long question has to be readable
                 before it is sent. Enter still sends on a desktop and
                 Shift+Enter breaks the line; on a phone the Return key breaks
                 the line and the button is the only way to send. All of that
                 lives in assistant-composer.js, shared by the three panels. --}}
            <textarea id="assistant-question" name="question" rows="1" autocomplete="off"
                      class="assistant-input"
                      placeholder="Pregunta lo que quieras de tu marca…"></textarea>

            <button type="button" class="assistant-tool" data-assistant-record
                    aria-pressed="false" title="Grabar una nota de voz">
                <span class="screen-reader-only">Grabar una nota de voz</span>
                <x-tabler-microphone aria-hidden="true" />
            </button>

            <button type="submit" class="dashboard-button assistant-send">
                <span class="screen-reader-only">Preguntar</span>
                <x-tabler-arrow-up aria-hidden="true" />
            </button>
        </div>
    </form>

</section>
