{{--
    Nueva marca — the assistant, the brand's details, and the 48 entregables.

    THE SAME ASSISTANT AS THE PROCESS SCREEN, with the same job: fill in what is
    missing. The only difference is that the brand does not exist yet, so the
    first thing worth keeping — a name, a file, a proposal — brings a Borrador
    row into being and everything hangs off that. See StartBrandDraft.

    The board is filtered to Pendientes by default, because on a new brand
    "what is missing" is the whole screen. Nothing here is required: Crear
    marca works with the form empty and the rest gets written later.

    Driven by process-assistant.js (the orb, the files, the proposal cards) and
    process.js (the filters and the counter) — the same two files as the process
    screen — plus client-draft.js for the autosave.
--}}
@php
    use App\Enums\DeliverableItem;
@endphp

<x-layouts.app title="Nueva marca" heading="nueva marca" :css="['process', 'assistant', 'attachments']"
               :scripts="['resources/js/process.js', 'resources/js/process-assistant.js', 'resources/js/client-draft.js', 'resources/js/lightbox.js']">

    <x-slot:actions>
        {{-- Only once there is a draft to throw away. Before that, Cancelar
             already does it: nothing has been written. --}}
        @if($draft)
            <form method="POST" action="{{ route('admin.clients.draft.discard', $draft) }}"
                  onsubmit="return confirm('Se borra este borrador y todo lo que tenga escrito. ¿Seguir?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="admin-button admin-button-ghost admin-button-sm">
                    Descartar borrador
                </button>
            </form>
        @endif

        <a href="{{ route('admin.clients.index') }}" class="admin-button admin-button-ghost admin-button-sm">
            Cancelar
        </a>
    </x-slot:actions>

    {{-- ================================================================
         The assistant — hand it the brandbook and it starts the brand
         ================================================================ --}}
    <x-brand-assistant
        :endpoint="route('admin.clients.draft.assistant')"
        greeting="¿Qué marca vamos a crear hoy?">

        @foreach ($conversation as $turn)
            <div class="assistant-message assistant-message-{{ $turn->isFromAssistant() ? 'assistant' : 'user' }}">
                {{-- A shared thread: several people write into it, so every turn
                     says whose it is. See x-turn-author. --}}
                <x-turn-author :author="$turn->author" :assistant="$turn->isFromAssistant()" />

                @foreach (preg_split('/\n{2,}/', trim($turn->body)) as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach

                {{-- Missed when the transcripts gained attachments: a draft's
                     turn carries files exactly like a saved brand's does. --}}
                <x-turn-attachments :names="$turn->attachments" :ids="$turn->attachment_ids" />
            </div>
        @endforeach

    </x-brand-assistant>

    {{-- Everything below posts as one form, so Crear marca saves the brand's
         details and whatever the assistant put in the board together. --}}
    <form method="POST" action="{{ route('admin.clients.store') }}"
          class="process-form" data-process-form data-draft-form
          data-save-url="{{ route('admin.clients.draft.save') }}" novalidate>
        @csrf

        {{-- Which draft this page is filling in. Written by the first autosave
             or the first assistant turn; empty until then. --}}
        <input type="hidden" name="draft" value="{{ $draft?->slug }}" data-draft-slug>

        <div class="admin-page-head">
            <div>
                <p class="admin-eyebrow" data-draft-name>{{ $draft?->name ?? 'Sin guardar' }}</p>
                <h2 class="admin-title">Una marca nueva</h2>
                <p class="admin-lead">
                    Súbele el brandbook al asistente y deja que llene lo que pueda, o
                    escríbelo tú. Se guarda solo mientras trabajas.
                </p>
                <p class="admin-meta" data-draft-status role="status" aria-live="polite"></p>
            </div>

            {{-- The autosave already does this, on a timer and on the way out.
                 The button is here because a timer is something you have to
                 trust, and this screen can hold an hour of work before anybody
                 is ready to press Crear marca. type="button": it saves the
                 draft, it does not submit the brand. --}}
            <button type="button" class="admin-button admin-button-secondary admin-button-sm"
                    data-draft-save>
                Guardar borrador
            </button>
        </div>

        {{-- --------------------------------------------------------------
             The brand itself
             -------------------------------------------------------------- --}}
        <section class="admin-card">
            <div class="admin-fields">

                <div class="admin-field">
                    <label for="name">Nombre de la marca</label>
                    <input id="name" name="name" type="text" data-draft-field
                           value="{{ old('name', $draft && $draft->name !== \App\Actions\StartBrandDraft::PLACEHOLDER_NAME ? $draft->name : '') }}"
                           placeholder="The Coffee Club"
                           @error('name') aria-invalid="true" @enderror required autofocus>
                    <span class="admin-hint">La URL se genera sola a partir del nombre.</span>
                </div>

                <div class="admin-field">
                    <label for="industry">Industria</label>
                    <input id="industry" name="industry" type="text" data-draft-field
                           value="{{ old('industry', $draft?->industry) }}" placeholder="Café de especialidad">
                </div>

                <div class="admin-field">
                    <label for="status">Estado</label>
                    <select id="status" name="status" required>
                        @foreach($statuses as $case)
                            <option value="{{ $case->value }}" @selected(old('status', 'activo') === $case->value)>
                                {{ $case->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <hr class="admin-divider">

                <div class="admin-field">
                    <label for="contact_name">Nombre de contacto</label>
                    <input id="contact_name" name="contact_name" type="text" data-draft-field
                           value="{{ old('contact_name', $draft?->contact_name) }}" placeholder="María García">
                </div>

                <div class="admin-field">
                    <label for="contact_email">Correo de contacto</label>
                    <input id="contact_email" name="contact_email" type="email" data-draft-field
                           value="{{ old('contact_email', $draft?->contact_email) }}"
                           placeholder="maria@thecoffeeclub.com"
                           @error('contact_email') aria-invalid="true" @enderror>
                </div>

                <div class="admin-field">
                    <label for="notes">Notas internas</label>
                    <textarea id="notes" name="notes" data-draft-field
                              placeholder="Contexto para el equipo. El cliente nunca ve esto.">{{ old('notes', $draft?->notes) }}</textarea>
                </div>

            </div>
        </section>

        {{-- --------------------------------------------------------------
             The 48, the same board as the process screen
             -------------------------------------------------------------- --}}
        <header class="process-list-head" style="margin-top:2rem">
            <div>
                <h3 class="admin-heading">los entregables</h3>
                <p class="admin-hint">
                    <b data-count-filled>{{ $deliverables->filledCount() }}</b> de 48 con contenido ·
                    <b data-count-required>{{ count(DeliverableItem::required()) - count($deliverables->missing()) }}</b>
                    de {{ count(DeliverableItem::required()) }} obligatorios.
                    Lo que el asistente proponga aterriza aquí; nada se guarda hasta que pulses el botón.
                </p>
            </div>

            <div class="process-filters" role="group" aria-label="Filtrar entregables">
                <button type="button" data-filter="todos" aria-pressed="false">Todos</button>
                <button type="button" data-filter="obligatorios" aria-pressed="false">Obligatorios</button>
                {{-- The default here, unlike the process screen: on a new brand
                     "what is missing" is the whole point of the page. --}}
                <button type="button" data-filter="pendientes" aria-pressed="true">Pendientes</button>
                <button type="button" data-filter="llenos" aria-pressed="false">Con contenido</button>
            </div>
        </header>

        <ol class="process-items" data-items>
            @foreach (DeliverableItem::cases() as $index => $item)
                @php $value = old("entregables.{$item->value}", $deliverables->value($item)); @endphp

                <li class="process-item"
                    data-required="{{ $item->isRequired() ? '1' : '0' }}"
                    data-filled="{{ trim((string) $value) !== '' ? '1' : '0' }}">

                    <label class="process-item-label" for="entregable_{{ $item->value }}">
                        <span class="process-item-number admin-numbers">{{ $index + 1 }}</span>
                        <span class="process-item-name">{{ $item->label() }}</span>
                        @if ($item->isRequired())
                            <span class="process-tag">Obligatorio</span>
                        @endif
                    </label>

                    <p class="process-item-hint" id="hint_{{ $item->value }}">{{ $item->hint() }}</p>

                    <textarea id="entregable_{{ $item->value }}"
                              name="entregables[{{ $item->value }}]"
                              rows="3"
                              aria-describedby="hint_{{ $item->value }}"
                              data-entregable="{{ $item->value }}">{{ $value }}</textarea>
                </li>
            @endforeach
        </ol>

        <div class="process-save">
            <button type="submit" class="admin-button admin-button-primary admin-button-lg">
                Crear marca
            </button>
            <p class="admin-hint">
                Deja de ser un borrador y pasa a la lista de marcas.
            </p>
        </div>

    </form>

</x-layouts.app>
