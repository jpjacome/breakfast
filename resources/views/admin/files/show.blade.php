{{--
    One brand's folder, opened.

    One kind of file: the brand's. Everything here is client-facing — the brand
    sees it in its own Archivos and the asset entregables link to it.

    Upload and delete post to the same routes the process and brand screens use;
    the folder has one way of being written to.
--}}
<x-layouts.app :title="$client?->name ?? 'Sin marca'" heading="archivos" css="files" :scripts="['resources/js/copy-link.js']">

    @if($client)
        <x-slot:actions>
            <a href="{{ route('admin.clients.process.edit', $client) }}" class="admin-button admin-button-outline">
                Ver proceso
            </a>
        </x-slot:actions>
    @endif

    <div class="admin-page-head">
        <div>
            <p class="admin-eyebrow">
                <a href="{{ route('admin.files.index') }}">Archivos</a> ·
                {{ $assets->count() }} {{ Str::plural('archivo', $assets->count()) }}
            </p>
            <h2 class="admin-title">{{ $client?->name ?? 'Sin marca' }}</h2>
        </div>
    </div>

    @unless($client)
        <p class="admin-hint">
            Archivos que llegaron adjuntos a una conversación con Brandy sin haber
            elegido marca. Son internos: nadie fuera de Breakfast los ve.
            Para archivarlos en una marca, descárgalos y súbelos a su carpeta.
        </p>
    @endunless

    @if($client)
    <form method="POST" action="{{ route('admin.clients.assets.store', $client) }}"
          enctype="multipart/form-data" class="admin-card file-upload">
        @csrf

        <div class="admin-field admin-filefield">
            <label for="files">Archivos</label>
            <input id="files" name="files[]" type="file" multiple required>
            <span class="admin-hint">Hasta 20 a la vez, 200 MB cada uno.</span>
        </div>

        <div class="admin-field">
            <label for="title">Nombre</label>
            <input id="title" name="title" type="text" value="{{ old('title') }}"
                   placeholder="Logotipo principal">
            <span class="admin-hint">
                Sólo se usa si subes un archivo. Con varios, cada uno se queda con el suyo.
            </span>
        </div>

        <x-admin.visibility-field />

        <button type="submit" class="admin-button admin-button-primary" style="justify-self:start">
            Subir archivos
        </button>
    </form>

    <p class="admin-hint">
        Lo que subas compartido lo ve la marca en sus archivos, corresponda o no a
        un entregable; lo interno no sale nunca de aquí y va marcado.
        <b>Copiar link</b> te da la URL para pegar en el entregable que le toque —
        ojo, un entregable que enlace un archivo interno le dará 404 a la marca.
    </p>
    @endif

    @if($assets->isEmpty())
        <div class="admin-empty">
            <p>{{ $client ? 'Esta marca todavía no tiene archivos.' : 'No hay archivos sin marca.' }}</p>
        </div>
    @else
        {{--
            Ordenar. Plain links rather than a <select> and some JavaScript: the
            order lives in the URL, so it survives a reload and can be sent to
            somebody else.

            Clicking the field you are already sorted by flips the direction,
            which is what every file manager does — and the reason `dir` is
            computed per link instead of being a control of its own.
        --}}
        @php
            $base = $client
                ? route('admin.files.show', $client)
                : route('admin.files.unfiled');
        @endphp

        <nav class="file-sort" aria-label="Ordenar archivos">
            <span>Ordenar por</span>
            @foreach(['fecha' => 'Fecha', 'nombre' => 'Nombre', 'peso' => 'Peso', 'tipo' => 'Tipo'] as $key => $label)
                <a href="{{ $base }}?orden={{ $key }}&dir={{ $sort === $key && $dir === 'desc' ? 'asc' : 'desc' }}"
                   @class(['is-current' => $sort === $key])
                   @if($sort === $key) aria-current="true" @endif>
                    {{ $label }}
                    @if($sort === $key)
                        <span aria-hidden="true">{{ $dir === 'asc' ? '↑' : '↓' }}</span>
                        <span class="screen-reader-only">
                            ({{ $dir === 'asc' ? 'ascendente' : 'descendente' }})
                        </span>
                    @endif
                </a>
            @endforeach
        </nav>

        <ul class="file-grid">
            @foreach($assets as $asset)
                <li class="file">
                    <a href="{{ $asset->url() }}" target="_blank" rel="noopener" class="file-preview">
                        @if($asset->isImage())
                            {{-- Streams through the gated route like every other
                                 read of this file, so a thumbnail is no wider a
                                 door than the link beside it. --}}
                            <img src="{{ $asset->url() }}" alt="{{ $asset->title }}" loading="lazy">
                        @elseif($asset->isPlayableVideo())
                            {{--
                                preload="metadata" — the browser pulls the first
                                frames and paints one, which IS the preview.
                                Never preload="auto": a folder of videos would
                                drag every file through the gated route in full
                                just to draw a grid, on a host whose worker pool
                                is shared with the public site (CLAUDE.md §3).

                                muted + playsinline so a stray tap cannot start
                                sound, and no controls — this is a thumbnail, and
                                the file opens by clicking through like the rest.
                            --}}
                            <video src="{{ $asset->url() }}" preload="metadata"
                                   muted playsinline tabindex="-1"
                                   aria-label="{{ $asset->title }}"></video>
                        @else
                            {{-- Same icon the client sees on their side, from
                                 the same method: one file, one picture of it. --}}
                            <x-dynamic-component :component="'tabler-'.$asset->icon()"
                                                 class="file-icon" aria-hidden="true" />
                        @endif
                    </a>

                    <div class="file-body">
                        <b>{{ $asset->title }}</b>
                        <p class="admin-meta">
                            {{ $asset->original_name }} · {{ $asset->humanSize() }}
                            · {{ $asset->created_at->translatedFormat('j M Y') }}
                            @if($asset->uploader) · {{ $asset->uploader->name }} @endif
                        </p>
                        @if($asset->source === App\Enums\AssetSource::Referencia)
                            {{-- Provenance, not a second kind of file: it says
                                 where this came from, never who may see it. --}}
                            <span class="{{ $asset->source->badgeClass() }}"
                                  title="{{ $asset->source->description() }}">
                                {{ $asset->source->label() }}
                            </span>
                        @endif
                    </div>

                    <div class="file-actions">
                        @if($client)
                            <x-admin.visibility-toggle :client="$client" :asset="$asset" />
                        @endif

                        <button type="button" class="admin-button admin-button-secondary admin-button-sm"
                                data-copy-link="{{ $asset->url() }}">
                            Copiar link
                        </button>

                        @if($client)
                            {{-- Delete stays brand-scoped: the write routes are
                                 BrandAssetController's, guarded by covers-client,
                                 and an unfiled file has no brand to guard it. --}}
                            <form method="POST"
                                  action="{{ route('admin.clients.assets.destroy', [$client, $asset]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="admin-button admin-button-ghost admin-button-sm">
                                    Eliminar
                                </button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

</x-layouts.app>
