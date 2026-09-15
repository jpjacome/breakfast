{{--
    Every brand a folder.

    The process screen uploads into a brand's folder while you are inside that
    brand. This screen is the other question — you are looking for a file and
    the brand is what you have to find first.
--}}
<x-layouts.app title="Archivos" heading="archivos" css="files">

    <div class="admin-page-head">
        <div>
            {{-- Counts BRANDS, and says so. "Carpetas" would be a lie the
                 moment the unfiled folder is on screen, because that one is
                 not a brand. --}}
            <p class="admin-eyebrow">{{ $clients->count() }} {{ Str::plural('marca', $clients->count()) }}</p>
            <h2 class="admin-title">Carpetas de marca</h2>
        </div>

        <form method="GET" action="{{ route('admin.files.index') }}" class="admin-filters">
            <div class="admin-field">
                <label class="screen-reader-only" for="q">Buscar</label>
                <input id="q" name="q" type="search" placeholder="Buscar marca" value="{{ $q }}">
            </div>
            <button type="submit" class="admin-button admin-button-outline">Buscar</button>
            @if($q)
                <a href="{{ route('admin.files.index') }}" class="admin-button admin-button-ghost">Limpiar</a>
            @endif
        </form>
    </div>

    @if($clients->isEmpty())
        <div class="admin-empty">
            <p>
                @if($q)
                    Ninguna marca coincide con esa búsqueda.
                @else
                    Todavía no hay marcas. Cada marca trae su carpeta al crearse.
                @endif
            </p>
        </div>
    @else
        <ul class="folder-grid">
            {{--
                Files attached to Brandy before anyone picked a brand. Beside the
                brands, never inside one — a file nobody has attributed could be
                about any of them, and parking it in an arbitrary folder is how
                it gets found by the wrong person later.

                Shown only when it has something in it: an empty folder that is
                always there is a folder people stop reading.
            --}}
            @if($unfiledCount > 0)
                <li>
                    <a href="{{ route('admin.files.unfiled') }}" class="folder folder-unfiled">
                        <x-tabler-folder-exclamation class="folder-icon" aria-hidden="true" />

                        <span class="folder-name">Sin marca</span>

                        <span class="admin-meta">
                            {{ $unfiledCount }} {{ Str::plural('archivo', $unfiledCount) }} ·
                            {{ \App\Models\BrandAsset::formatSize((int) $unfiledSize) }}
                        </span>
                    </a>
                </li>
            @endif

            @foreach($clients as $client)
                <li>
                    <a href="{{ route('admin.files.show', $client) }}" class="folder">
                        <x-tabler-folder class="folder-icon" aria-hidden="true" />

                        <span class="folder-name">{{ $client->name }}</span>

                        <span class="admin-meta">
                            @if($client->brand_assets_count === 0)
                                Vacía
                            @else
                                {{ $client->brand_assets_count }} {{ Str::plural('archivo', $client->brand_assets_count) }} · {{ \App\Models\BrandAsset::formatSize((int) $client->brand_assets_sum_size_bytes) }}
                            @endif
                        </span>

                    </a>
                </li>
            @endforeach
        </ul>
    @endif

</x-layouts.app>
