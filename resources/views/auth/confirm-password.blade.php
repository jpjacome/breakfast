<x-layouts.auth title="Confirma tu contraseña">

    <header class="auth-form__head">
        <h1 class="auth-form__title">confirma</h1>
        <p class="auth-form__sub">
            Esta es una zona sensible. Escribe tu contraseña para continuar.
        </p>
    </header>

    <x-auth.feedback />

    <form method="POST" action="{{ route('password.confirm') }}" novalidate>
        @csrf

        <div class="auth-form__fields">

            <div class="bkf-field">
                <label class="bkf-label" for="password">Contraseña</label>
                <input
                    class="bkf-input"
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    @error('password') aria-invalid="true" @enderror
                    required
                    autofocus
                >
            </div>

            <button type="submit" class="bkf-btn bkf-btn--primary bkf-btn--lg bkf-btn--block">
                Continuar
            </button>

        </div>
    </form>

</x-layouts.auth>
