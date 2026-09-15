{{--
    /portal/equipo — the brand owner invites teammates and sets what each one
    can reach. Owner-only: PortalSection::Equipo is not grantable, so a member
    never sees this page and the route middleware refuses the URL.
--}}
@php
    use App\Enums\AccessLevel;
    use App\Enums\PortalSection;
@endphp

<x-layouts.portal title="Equipo" :section="PortalSection::Equipo" css="permissions">

    <header class="dashboard-head">
        <h1>Equipo</h1>
        <p>
            Invita a tu gente y decide qué parte del portal ve cada quien.
            La suscripción y esta página se quedan contigo.
        </p>
    </header>

    {{-- Shown once, right after an invite whose mail did not go out. --}}
    @if (session('temp_password'))
        <div class="dashboard-note" role="status">
            <p>
                Contraseña temporal para <b>{{ session('temp_password_for') }}</b>.
                No la volveremos a mostrar.
            </p>
            <code class="dashboard-secret">{{ session('temp_password') }}</code>
        </div>
    @endif

    {{-- ------------------------------------------------------------------
         Current team
         ------------------------------------------------------------------ --}}
    <section class="dashboard-panel">
        <h2 class="dashboard-panel-title">Tu equipo</h2>

        @forelse ($members as $member)
            @php
                $granted = count(array_filter(
                    PortalSection::grantable(),
                    fn (PortalSection $s) => $member->canRead($s),
                ));
            @endphp

            <details class="member">
                <summary class="member-summary">
                    <span class="dashboard-avatar" aria-hidden="true">{{ $member->initials() }}</span>
                    <span class="member-identity">
                        <b>{{ $member->name }}</b>
                        <span>{{ $member->email }}</span>
                    </span>
                    <span class="member-count">
                        {{ $granted }} {{ $granted === 1 ? 'sección' : 'secciones' }}
                    </span>
                </summary>

                <form method="POST" action="{{ route('portal.equipo.update', $member) }}" class="dashboard-form">
                    @csrf
                    @method('PUT')

                    <x-portal.permission-grid :user="$member" />

                    <div class="dashboard-form-actions">
                        <button type="submit" class="dashboard-button">Guardar permisos</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('portal.equipo.destroy', $member) }}"
                      onsubmit="return confirm('¿Quitar el acceso de {{ $member->name }}?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="dashboard-button-quiet">Quitar del equipo</button>
                </form>
            </details>
        @empty
            <p class="dashboard-empty">
                Todavía eres la única persona en {{ $client->name }}.
            </p>
        @endforelse
    </section>

    {{-- ------------------------------------------------------------------
         Invite
         ------------------------------------------------------------------ --}}
    <section class="dashboard-panel">
        <h2 class="dashboard-panel-title">Invitar a alguien</h2>
        <p class="dashboard-panel-note">
            Le enviaremos un correo para que cree su propia contraseña.
        </p>

        <form method="POST" action="{{ route('portal.equipo.store') }}" class="dashboard-form">
            @csrf

            <div class="dashboard-form-fields">
                <div class="dashboard-field">
                    <label for="name">Nombre</label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}"
                           autocomplete="name" required>
                </div>

                <div class="dashboard-field">
                    <label for="email">Correo</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}"
                           autocomplete="email" required>
                </div>
            </div>

            <x-portal.permission-grid />

            <div class="dashboard-form-actions">
                <button type="submit" class="dashboard-button">Enviar invitación</button>
            </div>
        </form>
    </section>

</x-layouts.portal>
