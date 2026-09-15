{{--
    /forgot-password — Fortify POSTs to route('password.email').

    Says nothing about whether the address has an account: the form answers
    the same way either way, because a page that distinguished them would hand
    anybody the client list one address at a time. See PasswordRecoveryTest.
--}}
<x-layouts.site-remake :css="['auth']"
    title="Recuperar tu contraseña"
    description="Te enviamos un enlace para crear una contraseña nueva."
    :noindex="true">

    <article class="auth" data-plane>
        <div class="container">

          <h1 class="auth-heading" data-typewriter>
              ¿Olvidaste tu contraseña?
          </h1>

          <div class="auth-body" data-fade-in>

            <p class="auth-intro">
                Escribe tu correo y te mandamos un enlace para crear una nueva.
            </p>

            <x-auth.feedback />

            <form method="POST" action="{{ route('password.email') }}" class="auth-form">
                @csrf

                <div class="auth-field">
                    <label for="email">Correo</label>
                    <input type="email" id="email" name="email"
                           value="{{ old('email') }}"
                           inputmode="email"
                           autocomplete="username"
                           placeholder="maria@lamarca.com"
                           @error('email') aria-invalid="true" @enderror
                           required autofocus>
                </div>

                <button type="submit" class="button">Enviar enlace</button>
            </form>

            <p class="auth-foot">
                <a href="{{ route('login') }}">Volver a entrar</a>
            </p>

          </div>

        </div>
    </article>

</x-layouts.site-remake>
