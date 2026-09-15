{{--
    /two-factor-challenge — Fortify POSTs to route('two-factor.login').

    Between the password and the way in. Both boxes post to the same endpoint
    and only one is ever filled: the code from the app, or a recovery code for
    the day the phone is not in the room.
--}}
<x-layouts.site-remake :css="['auth']"
    title="Código de verificación"
    :noindex="true">

    <article class="auth" data-plane>
        <div class="container">

          <h1 class="auth-heading" data-typewriter>
              Un paso más
          </h1>

          <div class="auth-body" data-fade-in>

            <p class="auth-intro">
                Escribe el código de tu app de autenticación. Si no la tienes a
                mano, usa uno de tus códigos de recuperación.
            </p>

            <x-auth.feedback />

            <form method="POST" action="{{ route('two-factor.login') }}" class="auth-form">
                @csrf

                {{-- Neither box is required, deliberately: one of the two is
                     filled and the other is not, so marking either would make
                     the browser refuse a perfectly good submission. Fortify
                     decides which arrived. --}}
                <div class="auth-field">
                    <label for="code">Código</label>
                    <input type="text" id="code" name="code" class="auth-code"
                           inputmode="numeric"
                           autocomplete="one-time-code"
                           placeholder="000000"
                           autofocus>
                    <span class="auth-hint">6 dígitos</span>
                </div>

                <div class="auth-field">
                    <label for="recovery_code">O un código de recuperación</label>
                    <input type="text" id="recovery_code" name="recovery_code"
                           autocomplete="one-time-code">
                </div>

                <button type="submit" class="button">Verificar</button>
            </form>

          </div>

        </div>
    </article>

</x-layouts.site-remake>
