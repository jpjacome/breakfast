{{--
    The brand's files. Read-only — Breakfast uploads them.

    Everything in the folder is here, whether or not it belongs to an
    entregable: it is the brand's folder, not a by-product of the board.
--}}
{{-- Title and heading from the enum, so the sidebar and the page cannot end up
     calling this two different things. --}}
<x-layouts.portal :title="$section->label()" :section="$section">

    <header class="dashboard-head">
        <h1>{{ $section->label() }}</h1>
        <p>Todos los archivos de tu marca. Descárgalos y compártelos con quien los necesite.</p>
    </header>

    @if ($assets->isEmpty())
        {{-- Said plainly rather than left as an empty grid, which reads as
             something that failed to load. --}}
        <p class="dashboard-empty">
            Todavía no hay archivos. Aquí van a aparecer tus logos, tipografías
            y todo lo que produzcamos para la marca.
        </p>
    @else
        <ul class="asset-grid">
            @foreach ($assets as $asset)
                <li>
                    <a href="{{ $asset->url() }}" class="asset-card" target="_blank" rel="noopener">
                        <span class="asset-preview" aria-hidden="true">
                            @if ($asset->isImage())
                                {{-- Loaded through the same gated route as the
                                     download, so a thumbnail is not a way around
                                     the permission check. --}}
                                <img src="{{ $asset->url() }}" alt="" loading="lazy">
                            @else
                                {{-- The icon says what KIND of thing it is at a
                                     glance; the extension under the title says
                                     exactly which. Both, because "AI" is only
                                     obvious to the people who made the file. --}}
                                <x-dynamic-component :component="'tabler-'.$asset->icon()"
                                                     class="asset-icon" />
                            @endif
                        </span>

                        <b>{{ $asset->title }}</b>
                        <span class="asset-meta">
                            {{ $asset->extension() }} · {{ $asset->humanSize() }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

</x-layouts.portal>
