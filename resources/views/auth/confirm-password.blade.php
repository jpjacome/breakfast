{{--
    /user/confirm-password — Fortify POSTs to route('password.confirm').

    Reached from inside the app, before something sensitive (turning 2FA on,
    reading recovery codes). The person is already signed in, so this asks for
    the password they have rather than offering a new one.
--}}
<x-layouts.site-remake :css="['auth']"
    title="Confirma tu contraseña"
    :noindex="true">

    <article class="auth" data-plane>
        <div class="container">

          <h1 class="auth-heading" data-typewriter>
              Confirma que eres tú
          </h1>

          <div class="auth-body" data-fade-in>

            <p class="auth-intro">
                Esta es una zona sensible. Escribe tu contraseña para continuar.
            </p>

            <x-auth.feedback />

            <form method="POST" action="{{ route('password.confirm') }}" class="auth-form">
                @csrf

                <div class="auth-field">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password"
                           autocomplete="current-password"
                           @error('password') aria-invalid="true" @enderror
                           required autofocus>
                </div>

                <button type="submit" class="button">Continuar</button>
            </form>

          </div>

        </div>
    </article>

</x-layouts.site-remake>
