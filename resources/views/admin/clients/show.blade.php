@php
    use App\Enums\ContextDocumentKind;
    use App\Enums\UserRole;
@endphp

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
                <div class="bkf-row bkf-row--between">
                    <p class="bkf-eyebrow">Usuarios</p>
                    <span class="bkf-meta">{{ $client->users->count() }}</span>
                </div>

                {{-- One-time reveal. Flashed, so a refresh loses it for good. --}}
                @if (session('temp_password'))
                    <div class="alert alert--info" style="margin-top:var(--space-4);">
                        <b>Contraseña temporal</b>
                        <p style="margin-top:var(--space-1);font-size:var(--fs-2xs);">
                            Para {{ session('temp_password_for') }}. Solo se muestra ahora.
                        </p>
                        <code class="temp-pass">{{ session('temp_password') }}</code>
                        <p style="margin-top:var(--space-2);font-size:var(--fs-2xs);">
                            Mejor que la cambie con el enlace que le enviamos.
                        </p>
                    </div>
                @endif

                @forelse($client->users as $user)
                    <div class="userrow">
                        <span class="user-card__avatar">{{ $user->initials() }}</span>

                        <span class="userrow__main">
                            <b class="doc__title">{{ $user->name }}</b>
                            <span class="doc__meta">{{ $user->email }}</span>
                            <span class="doc__meta">
                                {{ $user->role->label() }}
                                @unless($user->email_verified_at)
                                    · <span class="bkf-text-accent">sin verificar</span>
                                @endunless
                            </span>
                        </span>

                        <span class="userrow__actions">
                            <form method="POST" action="{{ route('admin.clients.users.resend', [$client, $user]) }}">
                                @csrf
                                <button type="submit" class="bkf-btn bkf-btn--ghost bkf-btn--sm"
                                        title="Reenviar enlace para crear contraseña">Reenviar</button>
                            </form>
                            <form method="POST" action="{{ route('admin.clients.users.destroy', [$client, $user]) }}"
                                  onsubmit="return confirm('¿Quitar el acceso de {{ $user->name }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="bkf-btn bkf-btn--ghost bkf-btn--sm">Quitar</button>
                            </form>
                        </span>
                    </div>
                @empty
                    <p class="bkf-meta" style="margin-top:var(--space-3);">
                        Nadie de esta marca tiene acceso todavía.
                    </p>
                @endforelse

                {{-- add user --}}
                <details class="adduser" @if($errors->hasAny(['name','email','role'])) open @endif>
                    <summary>+ Dar acceso a alguien</summary>

                    <form method="POST" action="{{ route('admin.clients.users.store', $client) }}" novalidate>
                        @csrf

                        <div class="bkf-field">
                            <label class="bkf-label" for="u_name">Nombre</label>
                            <input class="bkf-input" id="u_name" name="name" type="text"
                                   value="{{ old('name') }}" placeholder="María García" required>
                        </div>

                        <div class="bkf-field">
                            <label class="bkf-label" for="u_email">Correo</label>
                            <input class="bkf-input" id="u_email" name="email" type="email"
                                   value="{{ old('email') }}" placeholder="maria@lamarca.com" required>
                        </div>

                        <div class="bkf-field">
                            <label class="bkf-label" for="u_role">Rol</label>
                            <select class="bkf-select" id="u_role" name="role" required>
                                @foreach(UserRole::clientRoles() as $case)
                                    <option value="{{ $case->value }}" @selected(old('role') === $case->value)>
                                        {{ $case->label() }}
                                    </option>
                                @endforeach
                            </select>
                            <span class="bkf-hint">
                                El dueño de marca verá facturación; el miembro solo el portal.
                            </span>
                        </div>

                        <button type="submit" class="bkf-btn bkf-btn--secondary bkf-btn--block">
                            Crear cuenta
                        </button>
                    </form>
                </details>
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
