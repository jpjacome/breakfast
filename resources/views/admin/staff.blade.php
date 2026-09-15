{{--
    /admin/equipo — the people who work at Breakfast.

    Everyone on staff reads this page; $canManage is true only for Admins, and
    it hides the forms rather than the roster. It is presentation only — what
    actually refuses a posted id is StaffController.
--}}
@php use App\Enums\UserRole; @endphp

<x-layouts.app title="Equipo" heading="equipo" css="staff">

    <div class="admin-page-head">
        <div>
            <h2 class="admin-title">Equipo Breakfast</h2>
        </div>
    </div>

    {{-- One-time reveal. Flashed, so a refresh loses it for good. --}}
    @if (session('temp_password'))
        <div class="admin-note admin-note-info" style="margin-bottom:1.5rem">
            <b>Contraseña temporal</b>
            <p class="admin-hint">Para {{ session('temp_password_for') }}. Solo se muestra ahora.</p>
            <code class="admin-secret">{{ session('temp_password') }}</code>
            <p class="admin-hint" style="margin-top:.5rem">
                Mejor que la cambie con el enlace que le enviamos.
            </p>
        </div>
    @endif

    <div class="admin-columns">

        {{-- ============================================================
             The roster
             ============================================================ --}}
        <section>
            {{-- No empty state: whoever is reading this page is on the list. --}}
            <div class="admin-card">
                @foreach($members as $member)
                    @php $isSelf = $member->is(auth()->user()); @endphp

                    <div class="admin-user">
                        <span class="admin-avatar" aria-hidden="true">{{ $member->initials() }}</span>

                        <span class="admin-user-main">
                            {{-- The "tú" marker is a ternary, not an inline
                                 @if: an @endif with text before it on the same
                                 line makes Blade emit the enclosing @endif
                                 literally and the page dies. See the note in
                                 admin/clients/show.blade.php. --}}
                            <span class="admin-doc-title">
                                {{ $member->name }}
                                @if($isSelf)
                                    <span class="admin-meta">· tú</span>
                                @endif
                            </span>
                            <span class="admin-doc-meta">{{ $member->email }}</span>
                            <span class="admin-doc-meta">
                                {{ $member->role->label() }}
                                {{-- An admin's assignments are stored but not
                                     consulted, so a count would read as a limit
                                     that is not one. --}}
                                @unless($member->coversEveryBrand())
                                    @php $covered = $member->assignedClients->count(); @endphp
                                    · {{ $covered }} {{ Str::plural('marca', $covered) }}
                                @else
                                    · todas las marcas
                                @endunless
                                {{-- La marca de verificación la pone el primer
                                     inicio de sesión, así que su ausencia dice
                                     exactamente esto: la invitación salió y
                                     nadie ha entrado con ella todavía. --}}
                                @unless($member->email_verified_at)
                                    · <span class="admin-flag">no ha entrado</span>
                                @endunless
                            </span>
                        </span>

                        @if($canManage)
                            <span class="admin-user-actions">
                                <form method="POST" action="{{ route('admin.staff.resend', $member) }}">
                                    @csrf
                                    <button type="submit" class="admin-button admin-button-ghost admin-button-sm"
                                            title="Reenviar enlace para crear contraseña">Reenviar</button>
                                </form>

                                @unless($isSelf)
                                    <form method="POST" action="{{ route('admin.staff.destroy', $member) }}"
                                          onsubmit="return confirm('¿Quitar del equipo a {{ $member->name }}? Pierde el acceso al back-office.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="admin-button admin-button-ghost admin-button-sm">Quitar</button>
                                    </form>
                                @endunless
                            </span>
                        @endif
                    </div>

                    @if($canManage)
                        <details class="admin-disclosure">
                            <summary>Editar a {{ Str::of($member->name)->explode(' ')->first() }}</summary>

                            <form method="POST" action="{{ route('admin.staff.update', $member) }}" novalidate>
                                @csrf
                                @method('PUT')

                                <div class="admin-field">
                                    <label for="name_{{ $member->id }}">Nombre</label>
                                    <input id="name_{{ $member->id }}" name="name" type="text"
                                           value="{{ $member->name }}" required>
                                </div>

                                {{-- El correo no se edita, ni el propio ni el de
                                     nadie: es el usuario con el que se entra y
                                     la única vía de vuelta si se pierde la
                                     contraseña. Una dirección equivocada se
                                     quita y se vuelve a invitar. --}}
                                <p class="admin-hint">
                                    El correo ({{ $member->email }}) no se cambia. Si hace falta
                                    otra dirección, quita la cuenta e invita de nuevo.
                                </p>

                                {{--
                                    Your own role is posted back unchanged rather
                                    than offered as a picker: demoting yourself
                                    leaves nobody able to undo it, and the
                                    controller refuses it anyway.
                                --}}
                                <div class="admin-field">
                                    @if($isSelf)
                                        <label for="role_{{ $member->id }}">Rol</label>
                                        <input type="hidden" name="role" value="{{ $member->role->value }}">
                                        <input id="role_{{ $member->id }}" type="text"
                                               value="{{ $member->role->label() }}" disabled>
                                        <span class="admin-hint">
                                            Tu propio rol lo cambia otro admin, no tú.
                                        </span>
                                    @else
                                        <label for="role_{{ $member->id }}">Rol</label>
                                        <select id="role_{{ $member->id }}" name="role" required>
                                            @foreach(UserRole::breakfastRoles() as $case)
                                                <option value="{{ $case->value }}" @selected($member->role === $case)>
                                                    {{ $case->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <span class="admin-hint">{{ $member->role->description() }}</span>
                                    @endif
                                </div>

                                <x-admin.brand-checklist :brands="$brands" :member="$member"
                                                         :id-prefix="'member'.$member->id" />

                                <button type="submit" class="admin-button admin-button-secondary admin-button-sm" style="justify-self:start">
                                    Guardar cambios
                                </button>
                            </form>
                        </details>
                    @endif
                @endforeach
            </div>
        </section>

        {{-- ============================================================
             Add someone
             ============================================================ --}}
        <aside class="admin-stack">
            @if($canManage)
                <div class="admin-card">
                    <p class="admin-eyebrow">Sumar a alguien</p>
                    <p class="admin-hint" style="margin-top:.5rem">
                        Le enviaremos un correo para que cree su propia contraseña.
                    </p>

                    <form method="POST" action="{{ route('admin.staff.store') }}" novalidate style="margin-top:1.25rem">
                        @csrf

                        <div class="admin-fields">
                            <div class="admin-field">
                                <label for="new_name">Nombre</label>
                                <input id="new_name" name="name" type="text"
                                       value="{{ old('name') }}" placeholder="María García" required>
                            </div>

                            <div class="admin-field">
                                <label for="new_email">Correo</label>
                                <input id="new_email" name="email" type="email"
                                       value="{{ old('email') }}" placeholder="maria@vamosdebreakfast.com" required>
                            </div>

                            <div class="admin-field">
                                <label for="new_role">Rol</label>
                                <select id="new_role" name="role" required>
                                    @foreach(UserRole::breakfastRoles() as $case)
                                        <option value="{{ $case->value }}"
                                                @selected(old('role', UserRole::Equipo->value) === $case->value)>
                                            {{ $case->label() }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="admin-hint">{{ UserRole::Equipo->description() }}</span>
                            </div>

                            <x-admin.brand-checklist :brands="$brands" id-prefix="new" />

                            <button type="submit" class="admin-button admin-button-primary admin-button-block">
                                Crear cuenta
                            </button>
                        </div>
                    </form>
                </div>
            @else
                <div class="admin-card admin-card-sunken">
                    <p class="admin-eyebrow">Solo lectura</p>
                    <p class="admin-hint" style="margin-top:.5rem">
                        Sumar, editar o quitar gente del equipo lo hace un admin.
                    </p>
                </div>
            @endif

            <div class="admin-card admin-card-sunken">
                <p class="admin-eyebrow">Los dos roles</p>
                @foreach(UserRole::breakfastRoles() as $case)
                    <p style="margin-top:.75rem">
                        <b>{{ $case->label() }}</b><br>
                        <span class="admin-meta">{{ $case->description() }}</span>
                    </p>
                @endforeach
            </div>
        </aside>

    </div>

</x-layouts.app>
