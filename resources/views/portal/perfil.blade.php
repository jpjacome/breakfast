{{--
    /portal/perfil — your own account.

    THE ONLY WRITABLE PAGE ON THIS SIDE, and it writes only you: your name and
    your password. Everything else in the portal is Breakfast writing and the
    brand reading, which is why PortalSection::Perfil is the one section that
    carries Write.

    Two forms, two Fortify endpoints, two error bags. The bags matter: a
    mistyped current password must not mark up the name field at the top of the
    page — see x-portal.form-errors, and x-admin.form-errors for the same
    reasoning on the other side.

    What is NOT here: the mail address, because it is the account you sign in
    with rather than a preference, and your role and your access, because
    somebody else grants those. Both are printed in the panel below as facts
    with the name of whoever changes them, so the page answers "why can I not
    see Reuniones?" instead of leaving it to a support mail.
--}}
<x-layouts.portal :title="$section->label()" :section="$section">

    <header class="dashboard-head">
        <h1>{{ $section->label() }}</h1>
        <p>Tus datos y tu contraseña. Sólo tú los cambias.</p>
    </header>

    {{-- ------------------------------------------------------------------
         Tu nombre
         ------------------------------------------------------------------ --}}
    <section class="dashboard-panel">
        <h2 class="dashboard-panel-title">Tus datos</h2>
        <p class="dashboard-panel-note">
            Tu nombre es el que ve tu equipo y con el que te saluda Brandy.
        </p>

        <x-portal.form-errors bag="updateProfileInformation" />

        <form method="POST" action="{{ route('user-profile-information.update') }}" class="dashboard-form">
            @csrf
            @method('PUT')

            <div class="dashboard-form-fields">
                <div class="dashboard-field">
                    <label for="name">Nombre</label>
                    <input type="text" id="name" name="name" autocomplete="name"
                           value="{{ old('name', $user->name) }}" required>
                </div>

                {{-- No email field, and none hidden either. App\Actions\Fortify\
                     UpdateUserProfileInformation validates the name alone and
                     drops everything else: the address is the account's
                     identity, not a preference on it. --}}
            </div>

            <div class="dashboard-form-actions">
                <button type="submit" class="dashboard-button">Guardar</button>
            </div>
        </form>
    </section>

    {{-- ------------------------------------------------------------------
         Contraseña
         ------------------------------------------------------------------ --}}
    <section class="dashboard-panel">
        <h2 class="dashboard-panel-title">Contraseña</h2>
        <p class="dashboard-panel-note">
            Pide la actual antes de cambiarla, para que una sesión abierta en un
            equipo ajeno no pueda quedarse con tu cuenta.
        </p>

        <x-portal.form-errors bag="updatePassword" />

        <form method="POST" action="{{ route('user-password.update') }}" class="dashboard-form">
            @csrf
            @method('PUT')

            {{-- The current one on its own row. Beside "Contraseña nueva" in a
                 two-column grid they read as a pair of alternatives rather than
                 as a sequence, and the pair you want side by side is the new
                 one and its confirmation. --}}
            <div class="dashboard-form-fields">
                <div class="dashboard-field">
                    <label for="current_password">Contraseña actual</label>
                    <input type="password" id="current_password" name="current_password"
                           autocomplete="current-password" required>
                </div>
            </div>

            <div class="dashboard-form-fields">
                <div class="dashboard-field">
                    <label for="password">Contraseña nueva</label>
                    <input type="password" id="password" name="password"
                           autocomplete="new-password" required>
                </div>

                <div class="dashboard-field">
                    <label for="password_confirmation">Repite la nueva</label>
                    <input type="password" id="password_confirmation" name="password_confirmation"
                           autocomplete="new-password" required>
                </div>
            </div>

            <p class="dashboard-panel-note">Mínimo ocho caracteres.</p>

            <div class="dashboard-form-actions">
                <button type="submit" class="dashboard-button">Cambiar contraseña</button>
            </div>
        </form>
    </section>

    {{-- ------------------------------------------------------------------
         Lo que no cambias tú
         ------------------------------------------------------------------ --}}
    <section class="dashboard-panel">
        <h2 class="dashboard-panel-title">Tu cuenta</h2>

        <dl class="profile-facts">
            <div>
                <dt>Correo</dt>
                {{-- Aquí y no en el formulario: es el usuario con el que entras.
                     Sin sello de verificación, porque nada en la app verifica
                     direcciones y un sello igual para todos no dice nada. --}}
                <dd>{{ $user->email }}</dd>
            </div>

            {{-- ⚠️ Only when there is one. Breakfast staff can open the portal —
                 the sidebar says "Breakfast" where a brand's name goes — and
                 they carry client_id = null, so printing $client->name here
                 was a 500 on this page for every admin who clicked Perfil. --}}
            @if ($client)
                <div>
                    <dt>Marca</dt>
                    <dd>{{ $client->name }}</dd>
                </div>
            @endif

            <div>
                <dt>Tu rol</dt>
                <dd>{{ $user->role->label() }}</dd>
            </div>

            <div>
                <dt>Lo que ves</dt>
                <dd>
                    @if ($sections === [])
                        Todavía nada más que esta página.
                    @else
                        {{ collect($sections)->map(fn ($section) => $section->label())->join(', ', ' y ') }}.
                    @endif
                </dd>
            </div>
        </dl>

        <p class="dashboard-panel-note">
            @if ($user->isBreakfast())
                Tu cuenta de Breakfast se administra en
                <a href="{{ route('admin.account') }}">Mi cuenta</a>, no aquí. Esta
                página es la del cliente; lo que cambies en ella es lo mismo, pero
                allí también está la verificación en dos pasos.
            @elseif ($user->isBrandOwner())
                Tu correo y tu rol los cambia tu equipo de Breakfast: escríbenos a
                <a href="mailto:info@vamosdebreakfast.com">info@vamosdebreakfast.com</a>.
                Lo que ve el resto de tu equipo lo decides tú en
                <a href="{{ route('portal.equipo') }}">Equipo</a>.
            @else
                Tu correo, tu rol y las secciones que ves los decide quien
                administra la marca desde Equipo. Nadie se amplía el acceso solo.
            @endif
        </p>
    </section>

</x-layouts.portal>
