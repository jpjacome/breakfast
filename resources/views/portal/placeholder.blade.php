<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portal · Breakfast</title>
    <link rel="icon" href="{{ asset('img/logo.png') }}" type="image/png">
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>

@php $user = auth()->user(); @endphp

<header class="bkf-topbar">
    <img src="{{ asset('img/logo.png') }}" alt="Breakfast" style="height:26px;width:auto;">
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="bkf-btn bkf-btn--ghost bkf-btn--sm">Salir</button>
    </form>
</header>

<main class="bkf-container bkf-section">
    <p class="bkf-eyebrow">{{ $user->client?->name ?? 'Sin marca asignada' }}</p>
    <h1 class="bkf-h1" style="margin-top:var(--space-3);">
        Hola, {{ Str::of($user->name)->explode(' ')->first() }}.
    </h1>
    <p class="bkf-lead" style="margin-top:var(--space-4);max-width:52ch;">
        Tu portal se construye en la siguiente fase: avance del proyecto por etapas,
        estrategia, entregas, reuniones y el IA Studio.
    </p>

    <div class="bkf-card" style="margin-top:var(--space-7);max-width:520px;">
        <p class="bkf-eyebrow">Cuenta</p>
        <dl style="margin-top:var(--space-3);display:grid;gap:var(--space-2);">
            <div class="bkf-row bkf-row--between">
                <dt class="bkf-text-muted bkf-body">Nombre</dt>
                <dd class="bkf-body">{{ $user->name }}</dd>
            </div>
            <div class="bkf-row bkf-row--between">
                <dt class="bkf-text-muted bkf-body">Correo</dt>
                <dd class="bkf-body">{{ $user->email }}</dd>
            </div>
            <div class="bkf-row bkf-row--between">
                <dt class="bkf-text-muted bkf-body">Rol</dt>
                <dd class="bkf-body">{{ $user->role->label() }}</dd>
            </div>
            <div class="bkf-row bkf-row--between">
                <dt class="bkf-text-muted bkf-body">Marca</dt>
                <dd class="bkf-body">{{ $user->client?->name ?? '—' }}</dd>
            </div>
        </dl>
    </div>
</main>

</body>
</html>
