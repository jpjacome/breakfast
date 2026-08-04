<x-layouts.auth title="Código de verificación">

    <header class="auth-form__head">
        <h1 class="auth-form__title">un paso más</h1>
        <p class="auth-form__sub">
            Escribe el código de tu app de autenticación. Si no la tienes a mano,
            usa uno de tus códigos de recuperación.
        </p>
    </header>

    <x-auth.feedback />

    <form method="POST" action="{{ route('two-factor.login') }}" novalidate>
        @csrf

        <div class="auth-form__fields">

            <div class="bkf-field">
                <label class="bkf-label" for="code">Código</label>
                <input
                    class="bkf-input bkf-tabular"
                    id="code"
                    name="code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    placeholder="000000"
                    required
                    autofocus
                >
                <span class="bkf-hint">6 dígitos</span>
            </div>

            <div class="bkf-field">
                <label class="bkf-label" for="recovery_code">O un código de recuperación</label>
                <input
                    class="bkf-input"
                    id="recovery_code"
                    name="recovery_code"
                    type="text"
                    autocomplete="one-time-code"
                >
            </div>

            <button type="submit" class="bkf-btn bkf-btn--primary bkf-btn--lg bkf-btn--block">
                Verificar
            </button>

        </div>
    </form>

</x-layouts.auth>
