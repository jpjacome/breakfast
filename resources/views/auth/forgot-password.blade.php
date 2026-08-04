<x-layouts.auth title="Recuperar clave">

    <header class="auth-form__head">
        <h1 class="auth-form__title">recuperar</h1>
        <p class="auth-form__sub">
            Escribe tu correo y te mandamos un enlace para crear una contraseña nueva.
        </p>
    </header>

    <x-auth.feedback />

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf

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
                    value="{{ old('email') }}"
                    @error('email') aria-invalid="true" @enderror
                    required
                    autofocus
                >
            </div>

            <button type="submit" class="bkf-btn bkf-btn--primary bkf-btn--lg bkf-btn--block">
                Enviar enlace
            </button>

        </div>
    </form>

    <p class="auth-form__foot">
        <a href="{{ route('login') }}">Volver a entrar</a>
    </p>

</x-layouts.auth>
