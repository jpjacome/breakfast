<x-layouts.auth title="Verifica tu correo">

    <header class="auth-form__head">
        <h1 class="auth-form__title">verifica tu correo</h1>
        <p class="auth-form__sub">
            Te mandamos un enlace de verificación. Ábrelo y quedas dentro.
            Si no llegó, te lo reenviamos.
        </p>
    </header>

    @if (session('status') === 'verification-link-sent')
        <div class="alert alert--success" role="status" style="margin-bottom: var(--space-5);">
            Listo. Te enviamos un enlace nuevo.
        </div>
    @endif

    <div class="auth-form__fields">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="bkf-btn bkf-btn--primary bkf-btn--lg bkf-btn--block">
                Reenviar enlace
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="bkf-btn bkf-btn--ghost bkf-btn--block">
                Salir
            </button>
        </form>
    </div>

</x-layouts.auth>
