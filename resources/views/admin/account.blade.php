{{--
    /admin/cuenta — your own account. /admin/equipo is everybody else's.

    Three forms, three destinations: name and mail go to Fortify's
    profile-information endpoint, the password to Fortify's password endpoint,
    and two-step to our own controller. Each validates into its own error bag
    so a wrong password never marks up the name field — see x-admin.form-errors.
--}}
<x-layouts.app title="Mi cuenta" heading="cuenta" css="account">

    <div class="admin-page-head">
        <div>
            <h2 class="admin-title">Mi cuenta</h2>
            <p class="admin-lead">
                Tus datos y tu acceso. Quién eres dentro de Breakfast y en qué marcas
                trabajas lo decide un admin desde Equipo.
            </p>
        </div>
    </div>

    <div class="admin-columns">

        <section class="admin-stack">

            {{-- ========================================================
                 Nombre y correo
                 ======================================================== --}}
            <div class="admin-card">
                <p class="admin-eyebrow">Datos</p>

                <x-admin.form-errors bag="updateProfileInformation" />

                <form method="POST" action="{{ route('user-profile-information.update') }}"
                      novalidate style="margin-top:1.25rem">
                    @csrf
                    @method('PUT')

                    <div class="admin-fields">
                        <div class="admin-field">
                            <label for="name">Nombre</label>
                            <input id="name" name="name" type="text" autocomplete="name"
                                   value="{{ old('name', $user->name) }}" required>
                        </div>

                        {{-- El correo no está aquí porque no se edita: es el
                             usuario con el que entras, no una preferencia. Se
                             lee en la ficha de al lado. --}}

                        <button type="submit" class="admin-button admin-button-secondary" style="justify-self:start">
                            Guardar cambios
                        </button>
                    </div>
                </form>
            </div>

            {{-- ========================================================
                 Contraseña
                 ======================================================== --}}
            <div class="admin-card">
                <p class="admin-eyebrow">Contraseña</p>

                <x-admin.form-errors bag="updatePassword" />

                <form method="POST" action="{{ route('user-password.update') }}"
                      novalidate style="margin-top:1.25rem">
                    @csrf
                    @method('PUT')

                    <div class="admin-fields">
                        <div class="admin-field">
                            <label for="current_password">Contraseña actual</label>
                            <input id="current_password" name="current_password" type="password"
                                   autocomplete="current-password" required>
                        </div>

                        <div class="admin-field">
                            <label for="password">Contraseña nueva</label>
                            <input id="password" name="password" type="password"
                                   autocomplete="new-password" required>
                            <span class="admin-hint">Mínimo ocho caracteres.</span>
                        </div>

                        <div class="admin-field">
                            <label for="password_confirmation">Repite la nueva</label>
                            <input id="password_confirmation" name="password_confirmation" type="password"
                                   autocomplete="new-password" required>
                        </div>

                        <button type="submit" class="admin-button admin-button-secondary" style="justify-self:start">
                            Cambiar contraseña
                        </button>
                    </div>
                </form>
            </div>

            {{-- ========================================================
                 Verificación en dos pasos

                 Three states rather than a switch: apagada, a medias — hay
                 secreto pero nadie ha probado que llegó al teléfono — y
                 activada. La del medio existe para que un QR mal escaneado no
                 te deje fuera en el siguiente inicio de sesión.
                 ======================================================== --}}
            <div class="admin-card">
                <p class="admin-eyebrow">Verificación en dos pasos</p>

                @if ($twoFactorEnabled)

                    <p class="admin-hint" style="margin-top:.5rem">
                        <span class="admin-badge admin-badge-success">Activada</span>
                    </p>
                    <p class="admin-hint" style="margin-top:.75rem">
                        Al entrar te pediremos un código de tu app además de la contraseña.
                    </p>

                    @if ($recoveryCodes)
                        <div class="admin-note admin-note-info" style="margin-top:1.25rem">
                            <b>Códigos de recuperación</b>
                            <p class="admin-hint">
                                Tu entrada si pierdes el teléfono. Cada uno sirve una vez y
                                solo se muestran ahora — guárdalos donde no esté tu móvil.
                            </p>
                            <code class="admin-secret two-factor-codes">{{ implode("\n", $recoveryCodes) }}</code>
                        </div>
                    @endif

                    <details class="admin-disclosure" style="margin-top:1.25rem">
                        <summary>Generar códigos de recuperación nuevos</summary>

                        <x-admin.form-errors bag="regenerateRecoveryCodes" />

                        <form method="POST" action="{{ route('admin.account.two-factor.recovery-codes') }}" novalidate>
                            @csrf

                            <div class="admin-field">
                                <label for="regenerate_password">Contraseña actual</label>
                                <input id="regenerate_password" name="current_password" type="password"
                                       autocomplete="current-password" required>
                                <span class="admin-hint">Los códigos anteriores dejarán de servir.</span>
                            </div>

                            <button type="submit" class="admin-button admin-button-outline admin-button-sm"
                                    style="justify-self:start">
                                Generar códigos
                            </button>
                        </form>
                    </details>

                    <details class="admin-disclosure">
                        <summary>Desactivar la verificación en dos pasos</summary>

                        <x-admin.form-errors bag="disableTwoFactor" />

                        <form method="POST" action="{{ route('admin.account.two-factor.disable') }}" novalidate>
                            @csrf
                            @method('DELETE')

                            <div class="admin-field">
                                <label for="disable_password">Contraseña actual</label>
                                <input id="disable_password" name="current_password" type="password"
                                       autocomplete="current-password" required>
                                <span class="admin-hint">
                                    Tu cuenta vuelve a depender solo de la contraseña.
                                </span>
                            </div>

                            <button type="submit" class="admin-button admin-button-ghost admin-button-sm"
                                    style="justify-self:start">
                                Desactivar
                            </button>
                        </form>
                    </details>

                @elseif ($twoFactorPending)

                    <p class="admin-hint" style="margin-top:.5rem">
                        Escanea este código con Google Authenticator, 1Password o la app que
                        uses, y escribe los seis dígitos que te dé.
                    </p>

                    <div class="two-factor-qr">{!! $qrCode !!}</div>

                    <p class="admin-hint">¿No puedes escanear? Escribe esta clave a mano:</p>
                    <code class="admin-secret">{{ $secretKey }}</code>

                    <x-admin.form-errors bag="confirmTwoFactorAuthentication" />

                    <form method="POST" action="{{ route('admin.account.two-factor.confirm') }}"
                          novalidate style="margin-top:1.25rem">
                        @csrf

                        <div class="admin-fields">
                            <div class="admin-field">
                                <label for="code">Código de la app</label>
                                <input id="code" name="code" type="text" inputmode="numeric"
                                       autocomplete="one-time-code" autofocus required>
                            </div>

                            <button type="submit" class="admin-button admin-button-secondary" style="justify-self:start">
                                Confirmar y activar
                            </button>
                        </div>
                    </form>

                    {{-- Sin contraseña: una configuración sin confirmar todavía
                         no protege nada, así que abandonarla no baja el listón. --}}
                    <form method="POST" action="{{ route('admin.account.two-factor.disable') }}"
                          style="margin-top:1rem">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="admin-button admin-button-ghost admin-button-sm">
                            Cancelar
                        </button>
                    </form>

                @else

                    <p class="admin-hint" style="margin-top:.5rem">
                        Un código de seis dígitos desde tu teléfono, además de la contraseña.
                        Con esto, una contraseña robada no basta para entrar al back-office.
                    </p>

                    <x-admin.form-errors bag="enableTwoFactor" />

                    <form method="POST" action="{{ route('admin.account.two-factor.enable') }}"
                          novalidate style="margin-top:1.25rem">
                        @csrf

                        <div class="admin-fields">
                            <div class="admin-field">
                                <label for="enable_password">Contraseña actual</label>
                                <input id="enable_password" name="current_password" type="password"
                                       autocomplete="current-password" required>
                            </div>

                            <button type="submit" class="admin-button admin-button-secondary" style="justify-self:start">
                                Activar
                            </button>
                        </div>
                    </form>

                @endif
            </div>

        </section>

        {{-- ============================================================
             Lo que no cambias tú
             ============================================================ --}}
        <aside class="admin-stack">

            <div class="admin-card">
                <div class="admin-row">
                    <span class="admin-avatar" aria-hidden="true">{{ $user->initials() }}</span>
                    <span class="admin-account-meta">
                        <b>{{ $user->name }}</b>
                        <span>{{ $user->role->label() }}</span>
                    </span>
                </div>

                <dl class="admin-stack" style="margin-top:1.5rem">
                    {{-- Aquí y no en el formulario: es un dato de la cuenta, no
                         algo que cambies tú. Sin marca de verificación: nada en
                         la app verifica direcciones todavía, así que el sello
                         diría lo mismo para todos y no significaría nada. --}}
                    <div>
                        <dt class="admin-meta">Correo</dt>
                        <dd>{{ $user->email }}</dd>
                    </div>

                    <div>
                        <dt class="admin-meta">Rol</dt>
                        <dd>{{ $user->role->label() }}</dd>
                        <dd class="admin-meta">{{ $user->role->description() }}</dd>
                    </div>

                    <div>
                        <dt class="admin-meta">Marcas</dt>
                        <dd>
                            @if ($brandCount === null)
                                Todas las marcas
                            @else
                                {{ $brandCount }} {{ Str::plural('marca', $brandCount) }}
                            @endif
                        </dd>
                    </div>

                </dl>
            </div>

            <div class="admin-card admin-card-sunken">
                <p class="admin-eyebrow">Tu rol y tus marcas</p>
                <p class="admin-hint" style="margin-top:.5rem">
                    Los cambia un admin desde <a href="{{ route('admin.staff.index') }}" class="admin-link">Equipo</a>,
                    no tú desde aquí. Nadie se asciende solo.
                </p>
            </div>

        </aside>

    </div>

</x-layouts.app>
