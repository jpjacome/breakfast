{{--
    The onboarding assistant, as it appears above the brand form.

    Same face as the assistant on the home screen — orb, centred column,
    composer — because it is the same assistant doing a different job, and two
    different-looking agents in one back office is two agents as far as anyone
    using it is concerned. The classes are the home screen's (.assistant*, in
    admin.css); what is added here is the file picker, the proposal cards and
    the thinking line, which the home screen has no use for.

    It is driven by resources/js/brand-profile.js, which also owns the form
    below it. Nothing here saves: the assistant writes into the form and a
    person presses the button.

    Nothing sits between the orb and the thread. The instructions that used to
    live there said what the placeholder and the tracker underneath already
    say, and a paragraph of preamble is the first thing anyone stops reading.

    Props:
      endpoint  where a turn is posted
      greeting  the line above the orb
--}}
@props([
    'endpoint',
    'greeting' => '¿Qué marca vamos a definir?',
])

{{-- The signed-in person, so a turn drawn before the reload signs itself
     the way the server signs the reloaded one. See process-assistant.js. --}}
<section class="assistant brand-assistant" data-brand-assistant data-endpoint="{{ $endpoint }}"
         data-me-initials="{{ auth()->user()?->initials() }}"
         data-me-name="{{ auth()->user()?->name }}">

    <h2 class="assistant-greeting">{{ $greeting }}</h2>

    <div class="assistant-orb">
        <x-assistant-orb />
    </div>

    <div class="assistant-thread" data-thread role="log" aria-live="polite"
         aria-label="Conversación con el asistente">
        {{ $slot }}
    </div>

    {{-- Proposals that would replace an answer already in the form. On a new
         brand this stays empty: every field starts blank, so proposals land
         straight in. It earns its place from the second document onward. --}}
    <div class="brand-proposals" data-proposals></div>

    <p class="brand-thinking" data-thinking hidden>Leyendo…</p>

    <form class="assistant-composer brand-composer" data-composer>

        <div class="assistant-ask">
            <label class="screen-reader-only" for="assistant-message">Mensaje al asistente</label>

            {{-- The paperclip carries the meaning; the word is for screen
                 readers. Same reasoning as the send arrow beside it.

                 Quiet rather than filled, and first rather than last: it is
                 the same composer the other two assistants use, and the send
                 arrow is the only button on the row that should read as the
                 action. Two yellow circles side by side asked which one
                 sends. --}}
            <label class="assistant-tool brand-attach" title="Adjuntar un archivo">
                <span class="screen-reader-only">Adjuntar archivo</span>
                <x-tabler-paperclip aria-hidden="true" />
                <input type="file" name="files[]" multiple data-files
                       accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.mp3,.m4a,.wav,.ogg,.webm,.flac,.aac">
            </label>

            {{-- The placeholder is where dropping and pasting are advertised.
                 They are the fastest ways in and the only ones with nothing on
                 screen to point at. --}}
            <textarea id="assistant-message" name="message" rows="1" autocomplete="off"
                      class="assistant-input"
                      placeholder="Escribe, arrastra un archivo aquí, o pega una captura…"></textarea>

            <button type="submit" class="admin-button admin-button-secondary assistant-send">
                <span class="screen-reader-only">Enviar</span>
                <x-tabler-arrow-up aria-hidden="true" />
            </button>
        </div>

        <div class="brand-attached" data-attached hidden></div>

    </form>

</section>
