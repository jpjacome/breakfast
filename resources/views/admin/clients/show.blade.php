@php
    use App\Enums\ClientStatus;
    use App\Enums\UserRole;
@endphp

{{-- css="permissions": this page renders x-portal.permission-grid, whose CSS
     ships with the component rather than with either shell. --}}
<x-layouts.app :title="$client->name" heading="marca" :css="['permissions', 'meetings', 'checklist']">

    <x-slot:actions>
        <a href="{{ route('admin.clients.index') }}" class="admin-button admin-button-ghost admin-button-sm">← Clientes</a>
    </x-slot:actions>

    {{-- ---------------------------------------------------------------- --}}
    <div class="admin-page-head">
        <div class="admin-row">
            <span class="admin-monogram admin-monogram-lg">{{ $client->initials() }}</span>
            <div>
                <h2 class="admin-title">{{ $client->name }}</h2>
                <p class="admin-meta">
                    {{ $client->industry ?: 'Sin industria' }}
                    · /{{ $client->slug }}
                    @if($client->onboarded_at)
                        · desde {{ $client->onboarded_at->translatedFormat('M Y') }}
                    @endif
                    {{-- Only when somebody has answered: "Marca registrada: sin
                         definir" in the header would be a line of nothing on
                         every brand that has not been asked. --}}
                    @if($client->trademark_registered !== null)
                        · marca registrada: {{ $client->trademarkLabel() }}
                    @endif
                </p>
            </div>
        </div>
        <span class="admin-badge {{ $client->status->badgeClass() }}">{{ $client->status->label() }}</span>
    </div>

    <div class="admin-columns">

        {{-- ============================================================
             Process and entregables — the whole of the work on this brand
             ============================================================ --}}
        <section style="grid-column:1/-1">
            {{-- Class names are fully qualified rather than imported: this
                 block compiles inside an enclosing if, and a PHP `use`
                 statement there is a parse error that kills the whole page.

                 Note also that no directive name may appear in a Blade comment
                 anywhere in this file. Comments are stripped AFTER directives
                 are compiled, so the word alone opens a real block. --}}
            @php
                $deliverables = $client->deliverablesOrNew();
                $currentStep = $client->currentStep();
                $stepsDone = $client->completedStepCount();
                $totalSteps = count(\App\Enums\ProcessStep::cases());
                $required = count(\App\Enums\DeliverableItem::required());
                $missing = $deliverables->missing();
            @endphp

            <div class="admin-card" style="margin-bottom:1.5rem;display:grid;gap:.875rem">
                <div class="admin-row admin-row-between" style="flex-wrap:wrap;gap:1rem">
                    <div>
                        <h3 class="admin-heading">proceso y entregables</h3>
                        <p class="admin-hint" style="margin-top:.375rem;max-width:52ch">
                            @if($currentStep)
                                Paso {{ $currentStep->number() }} de {{ $totalSteps }}:
                                {{ $currentStep->label() }}.
                            @elseif($stepsDone > 0)
                                Los {{ $stepsDone }} pasos están completos.
                            @else
                                El proceso todavía no empieza.
                            @endif
                            {{ $deliverables->filledCount() }} de 48 entregables con contenido,
                            {{ $required - count($missing) }} de {{ $required }} obligatorios.
                        </p>
                    </div>
                    <a href="{{ route('admin.clients.process.edit', $client) }}"
                       class="admin-button admin-button-secondary admin-button-sm">
                        Abrir proceso
                    </a>
                </div>

                <div class="brand-meter" role="img"
                     aria-label="Pasos completos: {{ $stepsDone }} de {{ $totalSteps }}">
                    <span style="width: {{ round($stepsDone / $totalSteps * 100) }}%"></span>
                </div>

                {{--
                    The overflow is worked out here rather than with an inline
                    @if in the sentence. An @endif with text before it on the
                    same line makes Blade emit the OUTER @endif literally, and
                    the whole page dies with a PHP parse error — this file did
                    exactly that until it was first rendered by a test.
                --}}
                @if($missing !== [])
                    @php
                        $shown = array_slice($missing, 0, 5);
                        $extra = count($missing) - count($shown);
                    @endphp
                    <p class="admin-hint">
                        Falta: {{ implode(', ', array_map(fn ($i) => $i->label(), $shown)) }}{{ $extra > 0 ? " y {$extra} más" : '' }}.
                    </p>
                @endif

                {{--
                    The Brand Egg sits INSIDE the proceso card rather than in
                    one of its own, because it is the same work seen from the
                    top: five layers synthesised from the entregables listed
                    just above. A separate card would suggest a second project.

                    Its state is derived, so this line cannot drift from the
                    screen it points at.
                --}}
                @php $eggState = $client->brandEggState(); @endphp

                <div class="admin-row admin-row-between"
                     style="flex-wrap:wrap;gap:1rem;padding-top:.875rem;border-top:1px solid var(--rule)">
                    <p class="admin-hint" style="max-width:52ch">
                        <b>Brand Egg:</b> {{ $eggState->description() }}
                    </p>
                    <a href="{{ route('admin.clients.egg.edit', $client) }}"
                       class="admin-button admin-button-outline admin-button-sm">
                        Abrir Brand Egg
                    </a>
                </div>
            </div>
        </section>

        {{-- ============================================================
             El checklist, y qué ha marcado la marca — SEG-05
             ============================================================
             Read-only on this side. The TEXT is written on the process board
             like any other entregable; the TICKS belong to the client, and
             this is the "seguimiento" the report asked for: what they have
             marked, who marked it, and when.
             ============================================================ --}}
        @if (! $checklist->isEmpty())
            <section style="grid-column:1/-1">
                <div class="admin-card">
                    <x-implementation-checklist :checklist="$checklist" :ticks="$ticks" />
                </div>
            </section>
        @endif

        {{-- ============================================================
             Reuniones — moved here from the process screen, which is the
             three steps and the 48 entregables and nothing else
             ============================================================ --}}
        <section style="grid-column:1/-1">
            <x-admin.meetings-card :client="$client" :meetings="$meetings" :audience="$audience" />
        </section>

        {{-- ============================================================
             The brand's files — everything Breakfast hands over
             ============================================================ --}}
        <section>
            <div class="admin-row admin-row-between" style="margin-bottom:1rem">
                <h3 class="admin-heading">archivos de la marca</h3>
                <span class="admin-meta">
                    {{ $client->brandAssets->count() }}
                    {{ Str::plural('archivo', $client->brandAssets->count()) }}
                </span>
            </div>

            {{--
                One box, and everything in it is the brand's. This used to be
                "contexto" — material uploaded for the assistant to read, kept
                out of the client's sight — which meant a team member could
                upload the toolkit here and the brand would never see it. The
                assistant's context is the 48 entregables now, so there is
                nothing for a second kind of file to be.
            --}}
            <p class="admin-lead" style="margin-bottom:1.25rem;max-width:60ch">
                Todo lo que le entregas a esta marca: logos, brandbooks, toolkits,
                presentaciones. <b>El cliente los ve en Archivos</b> y puede
                descargarlos.
            </p>

            <div class="admin-card" style="margin-bottom:1.5rem">
                <form method="POST"
                      action="{{ route('admin.clients.assets.store', $client) }}"
                      enctype="multipart/form-data"
                      novalidate>
                    @csrf

                    <div class="admin-fields">

                        <div class="admin-field admin-filefield">
                            <label for="files">Archivos</label>
                            <input id="files" name="files[]" type="file" multiple required>
                            <span class="admin-hint">Hasta 20 a la vez, 200 MB cada uno.</span>
                        </div>

                        <div class="admin-field">
                            <label for="asset_title">Nombre</label>
                            <input id="asset_title" name="title" type="text"
                                   value="{{ old('title') }}" placeholder="Toolkit de marca">
                            <span class="admin-hint">
                                Sólo se usa si subes un archivo. Con varios, cada uno se
                                queda con el suyo.
                            </span>
                        </div>

                        <button type="submit" class="admin-button admin-button-primary" style="justify-self:start">
                            Subir archivos
                        </button>

                    </div>
                </form>
            </div>

            @if($client->brandAssets->isEmpty())
                <div class="admin-empty">
                    <p>Todavía no hay archivos para esta marca.</p>
                </div>
            @else
                <div class="admin-doclist">
                    @foreach($client->brandAssets as $asset)
                        <div class="admin-doc">
                            <span class="admin-doc-kind">
                                <x-dynamic-component :component="'tabler-'.$asset->icon()" aria-hidden="true" />
                            </span>

                            <span class="admin-doc-main">
                                <span class="admin-doc-title">{{ $asset->title }}</span>
                                <span class="admin-doc-meta">
                                    {{ $asset->original_name }}
                                    · {{ $asset->humanSize() }}
                                    @if($asset->uploader) · {{ $asset->uploader->name }} @endif
                                </span>
                            </span>

                            <span class="admin-doc-actions">
                                <a href="{{ $asset->url() }}" target="_blank" rel="noopener"
                                   class="admin-button admin-button-ghost admin-button-sm">Ver</a>

                                <form method="POST"
                                      action="{{ route('admin.clients.assets.destroy', [$client, $asset]) }}"
                                      onsubmit="return confirm('¿Eliminar «{{ $asset->title }}»? No se puede deshacer.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="admin-button admin-button-ghost admin-button-sm">Eliminar</button>
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
        <aside class="admin-stack">

            <div class="admin-card">
                <p class="admin-eyebrow">Contacto</p>
                @if($client->contact_name || $client->contact_email)
                    <p style="margin-top:.75rem">{{ $client->contact_name }}</p>
                    @if($client->contact_email)
                        <a href="mailto:{{ $client->contact_email }}" class="admin-link">
                            {{ $client->contact_email }}
                        </a>
                    @endif
                @else
                    <p class="admin-meta" style="margin-top:.75rem">Sin contacto registrado.</p>
                @endif

                {{--
                    The brand's own details were writable only on the way in,
                    so a contact who changed job could not be corrected without
                    going to the database. Open on its own error bag, so a
                    failed save reopens this form and not the invite one.

                    Fields are namespaced brand[...] because the invite form on
                    this same page posts its own name and email.
                --}}
                <details class="admin-disclosure" style="margin-top:1rem"
                         @if($errors->hasAny(['brand.name', 'brand.contact_email', 'brand.status'])) open @endif>
                    <summary>Editar datos de la marca</summary>

                    <form method="POST" action="{{ route('admin.clients.update', $client) }}" novalidate>
                        @csrf
                        @method('PUT')

                        <div class="admin-field">
                            <label for="brand_name">Nombre</label>
                            <input id="brand_name" name="brand[name]" type="text"
                                   value="{{ old('brand.name', $client->name) }}" required>
                            <span class="admin-hint">
                                La dirección de la marca no cambia: sigue viviendo en
                                <code>/{{ $client->slug }}</code>, y su carpeta de archivos también.
                            </span>
                        </div>

                        <div class="admin-field">
                            <label for="brand_industry">Industria</label>
                            <input id="brand_industry" name="brand[industry]" type="text"
                                   value="{{ old('brand.industry', $client->industry) }}"
                                   placeholder="Cafetería de especialidad">
                        </div>

                        {{-- SEG-04. Three options, not a checkbox: "sin definir"
                             is a real answer and the default one, and a tickbox
                             would say "No" about every brand nobody has asked
                             yet — on a field the client can see. --}}
                        <div class="admin-field">
                            <label for="brand_trademark">Marca registrada</label>
                            <select id="brand_trademark" name="brand[trademark_registered]">
                                @php
                                    $trademark = old(
                                        'brand.trademark_registered',
                                        $client->trademark_registered === null
                                            ? ''
                                            : ($client->trademark_registered ? '1' : '0'),
                                    );
                                @endphp
                                <option value="" @selected($trademark === '')>Sin definir</option>
                                <option value="1" @selected($trademark === '1')>Sí</option>
                                <option value="0" @selected($trademark === '0')>No</option>
                            </select>
                            <span class="admin-hint">
                                Lo ve el cliente en su portal y Brandy puede responderlo.
                            </span>
                        </div>

                        <div class="admin-field">
                            <label for="brand_status">Estado</label>
                            <select id="brand_status" name="brand[status]" required>
                                @foreach(ClientStatus::selectable() as $case)
                                    <option value="{{ $case->value }}"
                                            @selected(old('brand.status', $client->status->value) === $case->value)>
                                        {{ $case->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="admin-field">
                            <label for="brand_contact_name">Nombre de contacto</label>
                            <input id="brand_contact_name" name="brand[contact_name]" type="text"
                                   value="{{ old('brand.contact_name', $client->contact_name) }}"
                                   placeholder="María García">
                        </div>

                        <div class="admin-field">
                            <label for="brand_contact_email">Correo de contacto</label>
                            <input id="brand_contact_email" name="brand[contact_email]" type="email"
                                   value="{{ old('brand.contact_email', $client->contact_email) }}"
                                   placeholder="maria@lamarca.com">
                        </div>

                        <div class="admin-field">
                            <label for="brand_notes">Notas</label>
                            <textarea id="brand_notes" name="brand[notes]" rows="4"
                                      placeholder="Lo que haga falta recordar de esta marca.">{{ old('brand.notes', $client->notes) }}</textarea>
                        </div>

                        <button type="submit" class="admin-button admin-button-secondary admin-button-block">
                            Guardar datos
                        </button>
                    </form>
                </details>
            </div>

            <div class="admin-card">
                <div class="admin-row admin-row-between">
                    <p class="admin-eyebrow">Usuarios</p>
                    <span class="admin-meta">{{ $client->users->count() }}</span>
                </div>

                {{-- One-time reveal. Flashed, so a refresh loses it for good. --}}
                @if (session('temp_password'))
                    <div class="admin-note admin-note-info" style="margin-top:1rem">
                        <b>Contraseña temporal</b>
                        <p class="admin-hint">Para {{ session('temp_password_for') }}. Solo se muestra ahora.</p>
                        <code class="admin-secret">{{ session('temp_password') }}</code>
                        <p class="admin-hint" style="margin-top:.5rem">
                            Mejor que la cambie con el enlace que le enviamos.
                        </p>
                    </div>
                @endif

                @forelse($client->users as $user)
                    <div class="admin-user">
                        <span class="admin-avatar" aria-hidden="true">{{ $user->initials() }}</span>

                        <span class="admin-user-main">
                            <span class="admin-doc-title">{{ $user->name }}</span>
                            <span class="admin-doc-meta">{{ $user->email }}</span>
                            <span class="admin-doc-meta">
                                {{ $user->role->label() }}
                                {{-- Igual que en /admin/equipo: la marca la pone
                                     el primer inicio de sesión, así que esto
                                     dice que la invitación sigue sin estrenar. --}}
                                @unless($user->email_verified_at)
                                    · <span class="admin-flag">no ha entrado</span>
                                @endunless
                            </span>
                        </span>

                        <span class="admin-user-actions">
                            <form method="POST" action="{{ route('admin.clients.users.resend', [$client, $user]) }}">
                                @csrf
                                <button type="submit" class="admin-button admin-button-ghost admin-button-sm"
                                        title="Reenviar enlace para crear contraseña">Reenviar</button>
                            </form>
                            <form method="POST" action="{{ route('admin.clients.users.destroy', [$client, $user]) }}"
                                  onsubmit="return confirm('¿Quitar el acceso de {{ $user->name }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="admin-button admin-button-ghost admin-button-sm">Quitar</button>
                            </form>
                        </span>
                    </div>

                    {{--
                        Where a brand's ceiling is set. Lowering an owner here
                        also lowers anyone on their team who held more — see
                        EnforceBrandPermissionCeiling.
                    --}}
                    <details class="admin-disclosure">
                        <summary>
                            Permisos de {{ Str::of($user->name)->explode(' ')->first() }}
                        </summary>

                        <form method="POST" action="{{ route('admin.clients.users.update', [$client, $user]) }}">
                            @csrf
                            @method('PUT')

                            <x-portal.permission-grid :user="$user" />

                            <button type="submit" class="admin-button admin-button-secondary admin-button-sm admin-button-block">
                                Guardar permisos
                            </button>
                        </form>
                    </details>
                @empty
                    <p class="admin-meta" style="margin-top:.75rem">
                        Nadie de esta marca tiene acceso todavía.
                    </p>
                @endforelse

                {{-- add user --}}
                <details class="admin-disclosure" @if($errors->hasAny(['name','email','role'])) open @endif>
                    <summary>+ Dar acceso a alguien</summary>

                    <form method="POST" action="{{ route('admin.clients.users.store', $client) }}" novalidate>
                        @csrf

                        <div class="admin-field">
                            <label for="u_name">Nombre</label>
                            <input id="u_name" name="name" type="text"
                                   value="{{ old('name') }}" placeholder="María García" required>
                        </div>

                        <div class="admin-field">
                            <label for="u_email">Correo</label>
                            <input id="u_email" name="email" type="email"
                                   value="{{ old('email') }}" placeholder="maria@lamarca.com" required>
                        </div>

                        <div class="admin-field">
                            <label for="u_role">Rol</label>
                            <select id="u_role" name="role" required>
                                @foreach(UserRole::clientRoles() as $case)
                                    <option value="{{ $case->value }}" @selected(old('role') === $case->value)>
                                        {{ $case->label() }}
                                    </option>
                                @endforeach
                            </select>
                            <span class="admin-hint">
                                El dueño de marca verá facturación y podrá invitar a su equipo.
                            </span>
                        </div>

                        {{--
                            Pre-ticked across the board. That is the shape a
                            brand owner should start in: they see all of their
                            brand. Untick rows when the account is a teammate
                            rather than the owner.
                        --}}
                        <x-portal.permission-grid :read-by-default="true" />

                        <button type="submit" class="admin-button admin-button-secondary admin-button-block">
                            Crear cuenta
                        </button>
                    </form>
                </details>
            </div>

            @if($client->notes)
                <div class="admin-card admin-card-sunken">
                    <p class="admin-eyebrow">Notas internas</p>
                    <p style="margin-top:.75rem;white-space:pre-line">{{ $client->notes }}</p>
                </div>
            @endif

            {{-- Archiving, last and quiet. Admin only: an Equipo member works
                 on the brands they were put on, and removing one is not
                 working on it. Behind a disclosure and behind a typed name,
                 because a confirm dialog on a page somebody visits daily gets
                 dismissed without being read. --}}
            @if(auth()->user()->isAdmin())
                <details id="archivar" class="admin-card admin-card-sunken admin-disclosure" style="margin-top:1.5rem" open>
                    <summary class="admin-heading">archivar marca</summary>

                    <form method="POST" action="{{ route('admin.clients.destroy', $client) }}"
                          class="admin-stack" style="margin-top:1rem">
                        @csrf
                        @method('DELETE')

                        <p class="admin-hint">
                            «{{ $client->name }}» sale de la lista y su gente pierde el portal
                            al instante. No se borra nada: puedes recuperarla entera desde la
                            papelera, y todos vuelven a entrar con los permisos que tenían.
                        </p>

                        <div class="admin-field">
                            <label for="confirmation">
                                Escribe <b>{{ $client->name }}</b> para confirmar
                            </label>
                            <input id="confirmation" name="confirmation" type="text"
                                   placeholder="{{ $client->name }}" required
                                   @error('confirmation') aria-invalid="true" @enderror>
                        </div>

                        <button type="submit" class="admin-button admin-button-ghost admin-button-sm"
                                style="justify-self:start">
                            Archivar marca
                        </button>
                    </form>
                </details>
            @endif

        </aside>

    </div>

</x-layouts.app>
