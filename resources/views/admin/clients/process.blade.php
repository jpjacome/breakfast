{{--
    The process screen: the three steps and the 48 entregables.

    Every field on this page comes from App\Enums\DeliverableItem — the form has
    no opinion of its own about what Breakfast delivers. Add a case there (plus
    its column) and it appears here and reaches the assistant, in that order.

    An entregable is always text. When the entregable IS an asset — the
    identificativo, las ilustraciones — the text is the link to the file. That
    is why there is no upload control beside a field: files live in the brand's
    asset folder and the field points at them.

    There is no save button per entregable and no status to move: filled is
    done, empty is pending, and the one Guardar at the bottom writes the lot.
--}}
@php
    use App\Enums\DeliverableItem;
    use App\Enums\ProcessStep;

    $current = $client->currentStep();
    $completed = $client->completedStepCount();
@endphp

<x-layouts.app :title="$client->name" heading="proceso" :css="['process', 'assistant', 'attachments']"
               :scripts="['resources/js/process.js', 'resources/js/process-assistant.js', 'resources/js/lightbox.js']">

    <x-slot:actions>
        <a href="{{ route('admin.clients.show', $client) }}" class="admin-button admin-button-ghost admin-button-sm">
            Volver a la marca
        </a>
    </x-slot:actions>

    {{-- ================================================================
         The assistant, on top — the same one as on the home screen. One
         agent, one face, wherever it turns up.

         It writes into the textareas below and has no way to save: every
         proposal is a card that does nothing until somebody clicks it.
         ================================================================ --}}
    <x-brand-assistant
        :endpoint="route('admin.clients.process.assistant', $client)"
        :greeting="$client->name">

        @foreach ($client->onboardingMessages as $turn)
            <div class="assistant-message assistant-message-{{ $turn->isFromAssistant() ? 'assistant' : 'user' }}">
                {{-- A shared thread: several people write into it, so every turn
                     says whose it is. See x-turn-author. --}}
                <x-turn-author :author="$turn->author" :assistant="$turn->isFromAssistant()" />

                @foreach (preg_split('/\n{2,}/', trim($turn->body)) as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach

                {{-- Was a row of bare filenames. The files are kept now, so an
                     image shows as an image — see x-turn-attachments. --}}
                <x-turn-attachments :names="$turn->attachments" :ids="$turn->attachment_ids" />
            </div>
        @endforeach

    </x-brand-assistant>

    <div class="admin-page-head">
        <div>
            <p class="admin-eyebrow">{{ $client->name }}</p>
            <h2 class="admin-title">Proceso y entregables</h2>
            <p class="admin-lead">
                Los tres pasos son lo que el cliente ve. Los 48 entregables son lo que
                el asistente sabe de esta marca: lo que no esté aquí, para él no existe.
            </p>
        </div>
    </div>

    {{-- ================================================================
         The three steps
         ================================================================ --}}
    <section class="process-steps" aria-label="Pasos del proceso">

        <header class="process-steps-head">
            <h3 class="admin-heading">el proceso</h3>
            <p class="admin-hint">
                {{ $completed }} de {{ ProcessStep::count() }} pasos completos.
                Un paso se cierra cuando tú lo dices — no depende de los entregables.
            </p>
        </header>

        <ol class="process-step-cards">
            @foreach (ProcessStep::cases() as $step)
                @php
                    $record = $client->stepRecord($step);
                    $isRunning = $record?->isRunning() ?? false;
                    $isComplete = $record?->isComplete() ?? false;
                @endphp

                <li class="process-step
                           @if($isComplete) process-step-done @elseif($isRunning) process-step-live @endif">

                    <p class="process-step-number admin-numbers">
                        {{ $step->number() }}<span>/{{ ProcessStep::count() }}</span>
                    </p>

                    <h4 class="process-step-name">
                        <x-dynamic-component :component="'tabler-'.$step->icon()" aria-hidden="true" />
                        {{ $step->label() }}
                    </h4>

                    <p class="process-step-note">{{ $step->description() }}</p>

                    <p class="process-step-state">
                        @if ($isComplete)
                            Completo el {{ $record->completed_at->translatedFormat('j \d\e F \d\e Y') }}
                            @if ($record->completedBy) · {{ $record->completedBy->name }} @endif
                        @elseif ($isRunning)
                            En curso desde el {{ $record->started_at->translatedFormat('j \d\e F \d\e Y') }}
                        @else
                            Sin empezar
                        @endif
                    </p>

                    <form method="POST" action="{{ route('admin.clients.process.step', $client) }}"
                          class="process-step-actions">
                        @csrf
                        <input type="hidden" name="step" value="{{ $step->value }}">

                        @if ($isComplete)
                            <button type="submit" name="action" value="reabrir"
                                    class="admin-button admin-button-ghost admin-button-sm">
                                Reabrir
                            </button>
                        @elseif ($isRunning)
                            <button type="submit" name="action" value="completar"
                                    class="admin-button admin-button-primary admin-button-sm">
                                Marcar completo
                            </button>
                        @elseif ($current !== null)
                            {{-- Disabled rather than hidden, and said why: an
                                 admin who clicks this anyway would just get the
                                 same sentence back as a flash message, one
                                 click later. Only one step may run at a time —
                                 see AdvanceProcessStep. --}}
                            <button type="button" class="admin-button admin-button-secondary admin-button-sm"
                                    disabled title="Cierra «{{ $current->label() }}» primero.">
                                Iniciar paso
                            </button>
                        @else
                            <button type="submit" name="action" value="iniciar"
                                    class="admin-button admin-button-secondary admin-button-sm">
                                Iniciar paso
                            </button>
                        @endif
                    </form>

                </li>
            @endforeach
        </ol>

    </section>

    {{-- ================================================================
         The brand's folder
         ================================================================ --}}
    <section class="process-assets" aria-label="Archivos de la marca">

        <header class="process-steps-head">
            <h3 class="admin-heading">archivos</h3>
            <p class="admin-hint">
                Lo que subas compartido lo ve la marca en sus archivos, corresponda
                o no a un entregable; lo interno se queda en el equipo y va marcado.
                <b>Copiar link</b> te da la URL para pegar en el entregable que le
                toque — un entregable que enlace un archivo interno le dará 404 a
                la marca.
            </p>
        </header>

        <form method="POST" action="{{ route('admin.clients.assets.store', $client) }}"
              enctype="multipart/form-data" class="admin-card process-asset-form">
            @csrf

            <div class="admin-field admin-filefield">
                <label for="asset_files">Archivos</label>
                <input id="asset_files" name="files[]" type="file" multiple required>
                <span class="admin-hint">Hasta 20 a la vez, 200 MB cada uno.</span>
            </div>

            <div class="admin-field">
                <label for="asset_title">Nombre</label>
                <input id="asset_title" name="title" type="text" value="{{ old('title') }}"
                       placeholder="Logotipo principal">
                <span class="admin-hint">
                    Sólo se usa si subes un archivo. Con varios, cada uno se queda
                    con el suyo.
                </span>
            </div>

            <x-admin.visibility-field />

            <button type="submit" class="admin-button admin-button-primary" style="justify-self:start">
                Subir archivos
            </button>
        </form>

        @if ($assets->isNotEmpty())
            <ul class="process-asset-list">
                @foreach ($assets as $asset)
                    <li class="process-asset">
                        <span class="process-asset-kind" aria-hidden="true">{{ $asset->extension() }}</span>

                        <div class="process-asset-main">
                            <b>{{ $asset->title }}</b>
                            <p class="admin-meta">
                                {{ $asset->original_name }} · {{ $asset->humanSize() }}
                                @if ($asset->uploader) · {{ $asset->uploader->name }} @endif
                            </p>
                        </div>

                        <div class="process-asset-actions">
                            <x-admin.visibility-toggle :client="$client" :asset="$asset" />

                            {{-- The point of this button: an entregable that IS an
                                 asset holds this URL as its text, and typing it by
                                 hand is how a wrong link gets saved. --}}
                            <button type="button" class="admin-button admin-button-secondary admin-button-sm"
                                    data-copy-link="{{ $asset->url() }}">
                                Copiar link
                            </button>

                            <a href="{{ $asset->url() }}" target="_blank" rel="noopener"
                               class="admin-button admin-button-ghost admin-button-sm">Ver</a>

                            <form method="POST"
                                  action="{{ route('admin.clients.assets.destroy', [$client, $asset]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="admin-button admin-button-ghost admin-button-sm">
                                    Eliminar
                                </button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

    </section>

    {{-- ================================================================
         The 48
         ================================================================ --}}
    <form method="POST" action="{{ route('admin.clients.process.update', $client) }}"
          class="process-form" data-process-form>
        @csrf
        @method('PUT')

        <header class="process-list-head">
            <div>
                <h3 class="admin-heading">los entregables</h3>
                <p class="admin-hint">
                    <b data-count-filled>{{ $deliverables->filledCount() }}</b> de 48 con contenido ·
                    <b data-count-required>{{ count(DeliverableItem::required()) - count($deliverables->missing()) }}</b>
                    de {{ count(DeliverableItem::required()) }} obligatorios.
                    @if($deliverables->exists && $deliverables->editor)
                        Última edición: {{ $deliverables->editor->name }},
                        {{ $deliverables->updated_at->diffForHumans() }}.
                    @endif
                </p>
            </div>

            {{-- Filters are presentation only: they hide rows, they never
                 change what gets posted. Every textarea stays in the form. --}}
            <div class="process-filters" role="group" aria-label="Filtrar entregables">
                <button type="button" data-filter="todos" aria-pressed="true">Todos</button>
                <button type="button" data-filter="obligatorios" aria-pressed="false">Obligatorios</button>
                <button type="button" data-filter="pendientes" aria-pressed="false">Pendientes</button>
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

                    {{-- Section 5 of the beta review: an entregable can carry
                         images and video. It stays TEXT — this pastes the
                         file's link at the cursor, and the brand page renders
                         a link to one of our images as the image. Nobody has
                         to build a URL by hand, which is the only part of that
                         somebody could get wrong. --}}
                    @if ($assets->isNotEmpty())
                        <details class="process-item-files">
                            <summary>Insertar archivo</summary>
                            <div class="process-file-list">
                                @foreach ($assets as $asset)
                                    <button type="button"
                                            class="process-file"
                                            data-insert-file="{{ route('assets.download', $asset) }}"
                                            data-target="{{ $item->value }}">
                                        <x-tabler-{{ $asset->icon() }} aria-hidden="true" />
                                        <span>{{ $asset->title }}</span>
                                        @if ($asset->isInternal())
                                            <span class="process-tag">Interno</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                            <p class="admin-hint">
                                Un archivo interno da 404 al cliente: si el entregable lo
                                enlaza, cámbialo a compartido.
                            </p>
                        </details>
                    @endif
                </li>
            @endforeach
        </ol>

        <div class="process-save">
            <button type="submit" class="admin-button admin-button-primary admin-button-lg">
                Guardar entregables
            </button>
            <p class="admin-hint">
                Vacío significa pendiente. No hay nada más que marcar.
            </p>
        </div>

    </form>

</x-layouts.app>
