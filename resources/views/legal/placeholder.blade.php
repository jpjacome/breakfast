<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Legal · Breakfast</title>
    <link rel="icon" href="{{ asset('img/logo.png') }}" type="image/png">
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<main class="bkf-container bkf-container--narrow bkf-section">
    <a href="{{ url('/') }}">
        <img src="{{ asset('img/logo.png') }}" alt="Breakfast" style="height:28px;width:auto;">
    </a>
    <h1 class="bkf-dot bkf-dot--lg" style="margin-top:var(--space-7);">legal</h1>
    <p class="bkf-lead" style="margin-top:var(--space-4);">
        Pendiente de redacción.
    </p>
</main>
</body>
</html>
