@props(['title' => null, 'heading' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ? $title.' · Breakfast' : 'Breakfast' }}</title>

    <link rel="icon" href="{{ asset('img/logo.png') }}" type="image/png">
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>

<div class="bkf-shell">

    {{-- ----------------------------------------------------------------
         Left rail
         ---------------------------------------------------------------- --}}
    <aside class="bkf-sidebar">

        <a href="{{ route('admin.home') }}" aria-label="Breakfast">
            <img src="{{ asset('img/logo.png') }}" alt="Breakfast" style="height:24px;width:auto;">
        </a>

        <nav class="bkf-nav" aria-label="Principal">
            <span class="bkf-nav-label">Breakfast</span>

            <a href="{{ route('admin.home') }}"
               class="bkf-nav-item"
               @if(request()->routeIs('admin.home')) aria-current="page" @endif>
                Inicio
            </a>

            <a href="{{ route('admin.clients.index') }}"
               class="bkf-nav-item"
               @if(request()->routeIs('admin.clients.*')) aria-current="page" @endif>
                Clientes
            </a>

            <span class="bkf-nav-label" style="margin-top:var(--space-4);">Próximamente</span>
            <span class="bkf-nav-item nav-soon">Entregas</span>
            <span class="bkf-nav-item nav-soon">Reuniones</span>
            <span class="bkf-nav-item nav-soon">IA Studio</span>
        </nav>

        <div style="margin-top:auto;">
            <div class="user-card">
                <span class="user-card__avatar">{{ auth()->user()->initials() }}</span>
                <span class="user-card__meta">
                    <b>{{ auth()->user()->name }}</b>
                    <span>{{ auth()->user()->role->label() }}</span>
                </span>
            </div>
            <form method="POST" action="{{ route('logout') }}" style="margin-top:var(--space-2);">
                @csrf
                <button type="submit" class="bkf-btn bkf-btn--ghost bkf-btn--sm bkf-btn--block">Salir</button>
            </form>
        </div>

    </aside>

    {{-- ----------------------------------------------------------------
         Content
         ---------------------------------------------------------------- --}}
    <div>
        <header class="bkf-topbar">
            <div>
                @if($heading)
                    <h1 class="bkf-dot bkf-dot--sm">{{ $heading }}</h1>
                @endif
            </div>
            {{ $actions ?? '' }}
        </header>

        <main class="bkf-container bkf-section">
            @if (session('status'))
                <div class="alert alert--success" role="status" style="margin-bottom:var(--space-6);">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert--danger" role="alert" style="margin-bottom:var(--space-6);">
                    <strong>Revisa lo siguiente:</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>

</div>

</body>
</html>
