{{--
    The first mail anybody ever gets from Breakfast: your account exists, here
    is how to pick a password and get in.

    One template, three senders — Breakfast staff creating a brand's user, a
    brand owner adding a teammate, and an admin adding someone to Breakfast
    itself. They differ only in $intro and $highlights, which is exactly why
    they share this file: the shape of the welcome should not drift between
    the three places that send it.

    Not a password-reset mail. It carries a reset token because that is the
    mechanism, but the recipient never asked for anything and has no password
    to reset, so it must not read like a security alert. See ResetPasswordLink
    for the message that IS one.

    Variables, all required except $highlights:
      $name        who is being greeted
      $intro       one line: what account this is and where
      $actionUrl   the tokenised link to /reset-password/{token}
      $expiresIn   how long that link is good for, already worded ("7 días")
      $highlights  array<string> of short "what is inside" lines, or []
--}}
<x-mail::message>
# Hola, {{ $name }}.

{{ $intro }}

Para entrar, elige tu contraseña con este botón:

<x-mail::button :url="$actionUrl">
Crear mi contraseña
</x-mail::button>

@if ($highlights !== [])
<x-mail::panel>
**Lo que vas a encontrar dentro:**

@foreach ($highlights as $highlight)
- {{ $highlight }}
@endforeach
</x-mail::panel>
@endif

El enlace vence en {{ $expiresIn }}. Si se te pasa, pídenos uno nuevo
respondiendo a este correo.

Nadie más puede usar este enlace: sólo funciona con tu dirección de correo.

— El equipo de {{ config('app.name') }}
</x-mail::message>
