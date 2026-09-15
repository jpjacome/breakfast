{{--
    /email/verify — the wait between signing in and being let through.

    Two posts, not one form: resending the link and signing out are different
    endpoints, and neither is the obvious thing to press.
--}}
<x-layouts.site-remake :css="['auth']"
    title="Verifica tu correo"
    :noindex="true">

    <article class="auth" data-plane>
        <div class="container">

          <h1 class="auth-heading" data-typewriter>
              Verifica tu correo
          </h1>

          <div class="auth-body" data-fade-in>

            <p class="auth-intro">
                Te mandamos un enlace de verificación. Ábrelo y quedas dentro.
                Si no llegó, te lo reenviamos.
            </p>

            {{-- Fortify flashes a sentinel here rather than a sentence, so
                 this one screen writes its own instead of using
                 <x-auth.feedback />. --}}
            @if (session('status') === 'verification-link-sent')
                <p class="auth-status" role="status">
                    Listo. Te enviamos un enlace nuevo.
                </p>
            @endif

            <div class="auth-actions">
                <form method="POST" action="{{ route('verification.send') }}">
                    @csrf
                    <button type="submit" class="button">Reenviar enlace</button>
                </form>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="button">Salir</button>
                </form>
            </div>

            <p class="auth-foot">
                ¿Sigue sin llegar? Escríbenos a
                <a href="mailto:info@vamosdebreakfast.com">info@vamosdebreakfast.com</a>.
            </p>

          </div>

        </div>
    </article>

</x-layouts.site-remake>
