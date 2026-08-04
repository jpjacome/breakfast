<x-layouts.auth title="Nueva contraseña">

    <header class="auth-form__head">
        <h1 class="auth-form__title">nueva clave</h1>
        <p class="auth-form__sub">
            Elige una contraseña nueva. Mínimo 8 caracteres.
        </p>
    </header>

    <x-auth.feedback />

    <form method="POST" action="{{ route('password.update') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="auth-form__fields">

            <div class="bkf-field">
                <label class="bkf-label" for="email">Correo</label>
                <input
                    class="bkf-input"
                    id="email"
                    name="email"
                    type="email"
                    inputmode="email"
                    autocomplete="username"
                    value="{{ old('email', $request->email) }}"
                    @error('email') aria-invalid="true" @enderror
                    required
                    autofocus
                >
            </div>

            <div class="bkf-field">
                <label class="bkf-label" for="password">Contraseña nueva</label>
                <input
                    class="bkf-input"
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    @error('password') aria-invalid="true" @enderror
                    required
                >
            </div>

            <div class="bkf-field">
                <label class="bkf-label" for="password_confirmation">Confirmar contraseña</label>
                <input
                    class="bkf-input"
                    id="password_confirmation"
                    name="password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                >
            </div>

            <button type="submit" class="bkf-btn bkf-btn--primary bkf-btn--lg bkf-btn--block">
                Guardar contraseña
            </button>

        </div>
    </form>

</x-layouts.auth>
