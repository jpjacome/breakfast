{{--
    /login — Fortify POSTs to route('login').

    Note there is no "create an account" link: registration is disabled by
    design. Breakfast creates the client and invites its users.
--}}

<x-layouts.auth title="Entrar">

    <header class="auth-form__head">
        <h1 class="auth-form__title">entrar</h1>
        <p class="auth-form__sub">
            Bienvenido de vuelta. Entra con el correo con el que te invitamos.
        </p>
    </header>

    <x-auth.feedback />

    <form method="POST" action="{{ route('login') }}" novalidate>
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
                    placeholder="maria@lamarca.com"
                    value="{{ old('email') }}"
                    @error('email') aria-invalid="true" @enderror
                    required
                    autofocus
                >
            </div>

            <div class="bkf-field">
                <div class="auth-form__row">
                    <label class="bkf-label" for="password">Contraseña</label>
                    @if (Route::has('password.request'))
                        <a class="auth-link" href="{{ route('password.request') }}">¿La olvidaste?</a>
                    @endif
                </div>
                <input
                    class="bkf-input"
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    @error('password') aria-invalid="true" @enderror
                    required
                >
            </div>

            <div class="auth-form__row">
                <label class="auth-check">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                    <span>Mantener sesión iniciada</span>
                </label>
            </div>

            <button type="submit" class="bkf-btn bkf-btn--primary bkf-btn--lg bkf-btn--block">
                Entrar
            </button>

        </div>
    </form>

    <p class="auth-form__foot">
        El acceso al portal es por invitación. Si tu marca trabaja con nosotros
        y todavía no tienes acceso, escríbenos a
        <a href="mailto:hola@vamosdebreakfast.com">hola@vamosdebreakfast.com</a>.
    </p>

</x-layouts.auth>
