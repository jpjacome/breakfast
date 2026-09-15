{{--
    /reset-password/{token} — Fortify POSTs to route('password.update').

    THE FIRST PAGE OF THIS APP MOST PEOPLE EVER SEE. The invitation mail lands
    here: an account exists, and this is where its owner picks a password. It
    is also where "olvidé mi contraseña" lands, which is why the heading says
    what the page does rather than welcoming anybody — one screen, two arrivals.

    Same shell and same vocabulary as /login, because it is the same door.
    On success Fortify redirects to /login with passwords.reset flashed, so
    the way in is the next thing they see; nothing here has to link to it.
--}}
<x-layouts.site-remake :css="['auth']"
    title="Elige tu contraseña"
    description="Elige la contraseña de tu cuenta en el portal de Breakfast."
    :noindex="true">

    <article class="auth" data-plane>
        <div class="container">

          <h1 class="auth-heading" data-typewriter>
              Elige tu contraseña
          </h1>

          <div class="auth-body" data-fade-in>

            <p class="auth-intro">
                Mínimo 8 caracteres. Con ella entras al portal desde cualquier
                dispositivo, así que elige una que no vayas a olvidar.
            </p>

            <x-auth.feedback />

            <form method="POST" action="{{ route('password.update') }}" class="auth-form">
                @csrf

                {{-- The token from the link. Without it the broker has no way
                     to know which account this is, and the post fails. --}}
                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <div class="auth-field">
                    <label for="email">Correo</label>
                    {{-- Prefilled from the link and rarely touched, but not
                         read-only: the broker checks the token against this
                         address, and somebody forwarded the mail to their real
                         inbox needs to be able to correct it. --}}
                    <input type="email" id="email" name="email"
                           value="{{ old('email', $request->email) }}"
                           inputmode="email"
                           autocomplete="username"
                           @error('email') aria-invalid="true" @enderror
                           required>
                </div>

                <div class="auth-field">
                    <label for="password">Contraseña nueva</label>
                    <input type="password" id="password" name="password"
                           autocomplete="new-password"
                           @error('password') aria-invalid="true" @enderror
                           required autofocus>
                </div>

                <div class="auth-field">
                    <label for="password_confirmation">Confírmala</label>
                    <input type="password" id="password_confirmation"
                           name="password_confirmation"
                           autocomplete="new-password"
                           required>
                </div>

                <button type="submit" class="button">Guardar contraseña</button>
            </form>

            <p class="auth-foot">
                ¿El enlace ya no sirve? Duran unos días.
                <a href="{{ route('password.request') }}">Pide uno nuevo</a>
                o escríbenos a
                <a href="mailto:info@vamosdebreakfast.com">info@vamosdebreakfast.com</a>.
            </p>

          </div>

        </div>
    </article>

</x-layouts.site-remake>
