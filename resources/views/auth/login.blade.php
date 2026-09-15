{{--
    /login — Fortify POSTs to route('login').

    Same layout as every other page of the site: the yellow card between the
    navbar and the footer, a typed heading, the content in a reading measure
    and a photograph beside it. Only the middle column differs — here it is
    the form instead of a letter.

    Note there is no "create an account" link: registration is disabled by
    design. Breakfast creates the client and invites its users.
--}}
<x-layouts.site-remake :css="['auth', 'login']"
    title="Entrar"
    description="Entra al portal de Breakfast para ver tu estrategia, tus entregas y tu contenido."
    :noindex="true">

    <article class="auth login" data-plane>
        <div class="container">

          <h1 class="auth-heading" data-typewriter>
              Bienvenido de vuelta
          </h1>

          <div class="auth-body" data-fade-in>

            <p class="auth-intro">
                Tu estrategia, tus entregas y tu contenido, en un solo lugar.
            </p>

            {{-- Where "tu contraseña quedó lista" lands after somebody sets
                 one, as well as a failed sign-in. The same component reports
                 on all six entrance screens. --}}
            <x-auth.feedback />

            <form method="POST" action="{{ route('login') }}" class="auth-form">
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

                <div class="auth-field">
                    <div class="auth-label-row">
                        <label for="password">Contraseña</label>
                        @if (Route::has('password.request'))
                            <a href="{{ route('password.request') }}" class="auth-forgot">¿La olvidaste?</a>
                        @endif
                    </div>
                    <input type="password" id="password" name="password"
                           autocomplete="current-password"
                           @error('password') aria-invalid="true" @enderror
                           required>
                </div>

                {{-- Square and black rather than the browser's rounded blue box,
                     which is the one control the UA styles in its own colour. --}}
                <label class="auth-remember">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                    <span>Mantener sesión iniciada</span>
                </label>

                <button type="submit" class="button">Entrar</button>
            </form>

            <p class="auth-foot">
                El acceso al portal es por invitación. Si tu marca trabaja con nosotros
                y todavía no tienes acceso, escríbenos a
                <a href="mailto:info@vamosdebreakfast.com">info@vamosdebreakfast.com</a>.
            </p>

          </div>

          <figure class="login-photo" data-fade-in>
              <img src="{{ asset('img/servicios/mesa-ventana.webp') }}"
                   alt="Mesa de café junto a una ventana con dos cortados, un periódico y bollería"
                   loading="lazy">
          </figure>

        </div>
    </article>

</x-layouts.site-remake>
