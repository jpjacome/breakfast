<x-layouts.app title="Inicio" heading="inicio">

    <x-slot:actions>
        <a href="{{ route('admin.clients.create') }}" class="bkf-btn bkf-btn--primary">
            Nueva marca
        </a>
    </x-slot:actions>

    <div class="page-head">
        <div>
            <p class="bkf-eyebrow">Panel Breakfast</p>
            <h2 class="bkf-h1" style="margin-top:var(--space-2);">
                Hola, {{ Str::of(auth()->user()->name)->explode(' ')->first() }}.
            </h2>
            <p class="bkf-lead" style="margin-top:var(--space-3);max-width:52ch;">
                Aquí administras las marcas y el contexto con el que trabajará la IA.
            </p>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    <section class="stats">
        <div class="stat stat--accent">
            <span class="bkf-eyebrow">Marcas</span>
            <span class="stat__value">{{ $clientCount }}</span>
            <span class="stat__note">{{ $activeCount }} {{ Str::plural('activa', $activeCount) }}</span>
        </div>
        <div class="stat">
            <span class="bkf-eyebrow">Archivos de contexto</span>
            <span class="stat__value">{{ $documentCount }}</span>
            <span class="stat__note">Material que alimentará al IA Studio</span>
        </div>
        <div class="stat">
            <span class="bkf-eyebrow">Sin procesar</span>
            <span class="stat__value">{{ $pendingCount }}</span>
            <span class="stat__note">Pendientes de indexar</span>
        </div>
    </section>

    {{-- ---------------------------------------------------------------- --}}
    <div class="bkf-split--offset" style="display:grid;gap:var(--space-7);margin-top:var(--space-8);">

        <section>
            <div class="bkf-row bkf-row--between" style="margin-bottom:var(--space-4);">
                <h3 class="bkf-dot bkf-dot--sm">marcas recientes</h3>
                @if($recentClients->isNotEmpty())
                    <a href="{{ route('admin.clients.index') }}" class="auth-link">Ver todas</a>
                @endif
            </div>

            @forelse($recentClients as $client)
                <a href="{{ route('admin.clients.show', $client) }}" class="doc">
                    <span class="monogram">{{ $client->initials() }}</span>
                    <span class="doc__main">
                        <span class="doc__title">{{ $client->name }}</span>
                        <span class="doc__meta">
                            {{ $client->industry ?: 'Sin industria' }}
                            · {{ $client->context_documents_count }}
                            {{ Str::plural('archivo', $client->context_documents_count) }}
                        </span>
                    </span>
                    <span class="bkf-badge {{ $client->status->badgeClass() }}">{{ $client->status->label() }}</span>
                </a>
            @empty
                <div class="empty">
                    <p>Todavía no hay marcas. Crea la primera para empezar a cargar su contexto.</p>
                    <a href="{{ route('admin.clients.create') }}" class="bkf-btn bkf-btn--outline bkf-btn--sm" style="margin-top:var(--space-4);">
                        Crear la primera marca
                    </a>
                </div>
            @endforelse
        </section>

        <section>
            <h3 class="bkf-dot bkf-dot--sm" style="margin-bottom:var(--space-4);">contexto reciente</h3>

            @forelse($recentDocuments as $doc)
                <div class="doc">
                    <span class="doc__kind" style="--doc-color: {{ $doc->kind->color() }}">{{ $doc->extension() }}</span>
                    <span class="doc__main">
                        <span class="doc__title">{{ $doc->title }}</span>
                        <span class="doc__meta">{{ $doc->client->name }} · {{ $doc->kind->label() }} · {{ $doc->humanSize() }}</span>
                    </span>
                </div>
            @empty
                <div class="empty">
                    <p>Sin archivos de contexto todavía.</p>
                </div>
            @endforelse
        </section>

    </div>

</x-layouts.app>
