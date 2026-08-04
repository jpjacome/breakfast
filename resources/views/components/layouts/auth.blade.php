@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ? $title.' · Breakfast' : 'Breakfast' }}</title>

    <link rel="icon" href="{{ asset('img/logo.png') }}" type="image/png">

    {{-- Self-hosted Inter: preload links + @font-face, emitted from
         the fonts manifest built by laravel-vite-plugin. --}}
    {{ Vite::fonts() }}

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>

<div class="auth">

    {{-- ---------------------------------------------------------------
         The yellow half. This is the whole brand statement: the sticker
         wordmark on Breakfast Yellow, one honest line, nothing else.
         --------------------------------------------------------------- --}}
    <aside class="auth-brand">
        <a href="{{ url('/') }}" aria-label="Breakfast — inicio">
            <img src="{{ asset('img/logo.png') }}" alt="Breakfast" class="auth-brand__logo" width="795" height="139">
        </a>

        <div>
            <p class="auth-brand__say">Una conversación larga con café.</p>
            <p class="auth-brand__note">
                Tu estrategia, tus entregas y tu contenido, en un solo lugar.
                Entra para ver en qué va tu proceso.
            </p>
        </div>

        <div class="auth-brand__foot">
            <span>&copy; {{ date('Y') }} Breakfast</span>
            <a href="{{ url('/legal/privacidad') }}">Privacidad</a>
            <a href="{{ url('/legal/terminos') }}">Términos</a>
        </div>
    </aside>

    {{-- ---------------------------------------------------------------
         The form half. Off-white, deliberately underfilled.
         --------------------------------------------------------------- --}}
    <main class="auth-panel">
        <div class="auth-form">
            {{ $slot }}
        </div>
    </main>

</div>

</body>
</html>
