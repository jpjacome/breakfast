@php use App\Enums\ContextDocumentKind; @endphp

<x-layouts.app :title="$client->name" heading="marca">

    <x-slot:actions>
        <a href="{{ route('admin.clients.index') }}" class="bkf-btn bkf-btn--ghost bkf-btn--sm">← Clientes</a>
    </x-slot:actions>

    {{-- ---------------------------------------------------------------- --}}
    <div class="page-head">
        <div class="bkf-row" style="gap:var(--space-4);">
            <span class="monogram monogram--lg">{{ $client->initials() }}</span>
            <div>
                <h2 class="bkf-h1">{{ $client->name }}</h2>
                <p class="bkf-meta" style="margin-top:var(--space-2);">
                    {{ $client->industry ?: 'Sin industria' }}
                    · /{{ $client->slug }}
                    @if($client->onboarded_at)
                        · desde {{ $client->onboarded_at->translatedFormat('M Y') }}
                    @endif
                </p>
            </div>
        </div>
        <span class="bkf-badge {{ $client->status->badgeClass() }}">{{ $client->status->label() }}</span>
    </div>

    <div style="display:grid;grid-template-columns:1.6fr 1fr;gap:var(--space-7);align-items:start;">

        {{-- ============================================================
             Context documents — the point of this screen
             ============================================================ --}}
        <section>
            <div class="bkf-row bkf-row--between" style="margin-bottom:var(--space-4);">
                <h3 class="bkf-dot bkf-dot--sm">contexto</h3>
                <span class="bkf-meta">
                    {{ $client->contextDocuments->count() }}
                    {{ Str::plural('archivo', $client->contextDocuments->count()) }}
                </span>
            </div>

            <p class="bkf-body bkf-text-muted" style="margin-bottom:var(--space-5);max-width:60ch;">
                El material del que la IA aprenderá a hablar como esta marca: brief,
                estrategia, brandbook, notas de taller. Solo lo ve el equipo Breakfast.
            </p>

            {{-- upload --}}
            <div class="bkf-card" style="margin-bottom:var(--space-6);">
                <form method="POST"
                      action="{{ route('admin.clients.context.store', $client) }}"
                      enctype="multipart/form-data"
                      novalidate>
                    @csrf

                    <div class="auth-form__fields">

                        <div class="bkf-field filefield">
                            <label class="bkf-label" for="file">Archivo</label>
                            <input id="file" name="file" type="file" required
                                   accept=".pdf,.doc,.docx,.ppt,.pptx,.txt,.md,.rtf,.csv,.xlsx">
                            <span class="bkf-hint">PDF, Word, PowerPoint, Excel o texto. Hasta 25 MB.</span>
                        </div>

                        <div style="display:grid;grid-template-columns:1.5fr 1fr;gap:var(--space-4);">
                            <div class="bkf-field">
                                <label class="bkf-label" for="title">Título</label>
                                <input class="bkf-input" id="title" name="title" type="text"
                                       value="{{ old('title') }}" placeholder="Brief de marca 2026" required>
                            </div>
                            <div class="bkf-field">
                                <label class="bkf-label" for="kind">Tipo</label>
                                <select class="bkf-select" id="kind" name="kind" required>
                                    @foreach(ContextDocumentKind::cases() as $case)
                                        <option value="{{ $case->value }}" @selected(old('kind') === $case->value)>
                                            {{ $case->label() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="bkf-field">
                            <label class="bkf-label" for="description">Descripción</label>
                            <input class="bkf-input" id="description" name="description" type="text"
                                   value="{{ old('description') }}"
                                   placeholder="Opcional — qué contiene y de dónde salió">
                        </div>

                        <button type="submit" class="bkf-btn bkf-btn--primary">Subir contexto</button>

                    </div>
                </form>
            </div>

            {{-- list --}}
            @if($client->contextDocuments->isEmpty())
                <div class="empty">
                    <p>Todavía no hay contexto cargado para esta marca. Sube el brief o la estrategia para empezar.</p>
                </div>
            @else
                <div class="doclist">
                    @foreach($client->contextDocuments as $doc)
                        <div class="doc">
                            <span class="doc__kind" style="--doc-color: {{ $doc->kind->color() }}">
                                {{ $doc->extension() }}
                            </span>

                            <span class="doc__main">
                                <span class="doc__title">{{ $doc->title }}</span>
                                <span class="doc__meta">
                                    {{ $doc->kind->label() }}
                                    · {{ $doc->humanSize() }}
                                    · {{ $doc->created_at->diffForHumans() }}
                                    @if($doc->uploader) · {{ $doc->uploader->name }} @endif
                                </span>
                                @if($doc->description)
                                    <span class="doc__meta">{{ $doc->description }}</span>
                                @endif
                            </span>

                            <span class="doc__actions">
                                @if(! $doc->processed_at)
                                    <span class="bkf-badge" title="Aún no indexado para la IA">sin procesar</span>
                                @endif

                                <a href="{{ route('admin.clients.context.download', [$client, $doc]) }}"
                                   class="bkf-btn bkf-btn--ghost bkf-btn--sm">Descargar</a>

                                <form method="POST"
                                      action="{{ route('admin.clients.context.destroy', [$client, $doc]) }}"
                                      onsubmit="return confirm('¿Eliminar «{{ $doc->title }}»? No se puede deshacer.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="bkf-btn bkf-btn--ghost bkf-btn--sm">Eliminar</button>
                                </form>
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- ============================================================
             Sidebar
             ============================================================ --}}
        <aside class="bkf-stack--lg" style="display:grid;">

            <div class="bkf-card">
                <p class="bkf-eyebrow">Contacto</p>
                @if($client->contact_name || $client->contact_email)
                    <p class="bkf-body" style="margin-top:var(--space-3);">{{ $client->contact_name }}</p>
                    @if($client->contact_email)
                        <a href="mailto:{{ $client->contact_email }}" class="bkf-meta" style="border-bottom:2px solid var(--bkf-yellow);">
                            {{ $client->contact_email }}
                        </a>
                    @endif
                @else
                    <p class="bkf-meta" style="margin-top:var(--space-3);">Sin contacto registrado.</p>
                @endif
            </div>

            <div class="bkf-card">
                <p class="bkf-eyebrow">Usuarios</p>
                @forelse($client->users as $user)
                    <div class="bkf-row" style="margin-top:var(--space-3);gap:var(--space-3);">
                        <span class="user-card__avatar">{{ $user->initials() }}</span>
                        <span>
                            <b class="bkf-body">{{ $user->name }}</b><br>
                            <span class="bkf-meta">{{ $user->role->label() }}</span>
                        </span>
                    </div>
                @empty
                    <p class="bkf-meta" style="margin-top:var(--space-3);">
                        Nadie de esta marca tiene acceso todavía. Las invitaciones llegan en la siguiente fase.
                    </p>
                @endforelse
            </div>

            @if($client->notes)
                <div class="bkf-card bkf-card--sunken">
                    <p class="bkf-eyebrow">Notas internas</p>
                    <p class="bkf-body" style="margin-top:var(--space-3);white-space:pre-line;">{{ $client->notes }}</p>
                </div>
            @endif

        </aside>

    </div>

</x-layouts.app>
