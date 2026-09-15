{{--
    $css:     the page's own stylesheet by name — css="process" loads
              resources/css/process.css. general.css and admin.css are the two
              every admin screen gets; anything else belongs to one blade and
              ships only with it.

              Takes a LIST as well as a name: :css="['process', 'assistant']".
              A page owns one stylesheet, but it may also render a component
              that brings its own — the assistant panel and the permission grid
              both appear in two shells, so neither shell's file can hold them.
              Naming both is how a page says which components it renders.

    $scripts: extra Vite entries for pages that need JavaScript. Merged into the
              one @vite call rather than added as a second one — calling @vite
              twice emits the dev client twice.
--}}
@props(['title' => null, 'heading' => null, 'css' => null, 'scripts' => []])

@php $user = auth()->user(); @endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ? $title.' · Breakfast' : 'Breakfast' }}</title>

    <link rel="icon" href="{{ asset('img/favicon.png') }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('img/favicon.png') }}">
    {{ Vite::fonts() }}

    {{-- Above the stylesheet on purpose — see the component. --}}
    <x-theme-boot />

    @vite(array_merge([
        'resources/css/general.css',
        'resources/css/admin.css',
        ...array_map(fn ($name) => "resources/css/{$name}.css", array_filter((array) $css)),
    ], $scripts))
</head>
<body class="admin">

<div class="admin-frame">

    {{-- ----------------------------------------------------------------
         Left rail
         ---------------------------------------------------------------- --}}
    <aside class="admin-sidebar">

        <a href="{{ route('admin.home') }}" class="admin-logo" aria-label="Breakfast">
            <img src="{{ asset('img/logo.png') }}" alt="Breakfast" width="795" height="139">
        </a>

        {{-- Icon then label, the same shape as the client rail. The icons are
             not decoration: at a glance the rail is scanned by shape long
             before the word is read, and this side had none while the client's
             did. Archivos takes the folder the file manager already uses for
             every brand, so the icon in the rail is the icon on the screen it
             opens. --}}
        <nav class="admin-nav" aria-label="Principal">

            <a href="{{ route('admin.home') }}"
               class="admin-nav-link"
               @if(request()->routeIs('admin.home')) aria-current="page" @endif>
                <x-tabler-home aria-hidden="true" />
                <span>Inicio</span>
            </a>

            <a href="{{ route('admin.clients.index') }}"
               class="admin-nav-link"
               @if(request()->routeIs('admin.clients.*')) aria-current="page" @endif>
                <x-tabler-briefcase aria-hidden="true" />
                <span>Clientes</span>
            </a>

            {{-- The same icon the client rail gives Reuniones: one concept,
                 one picture of it on both sides. --}}
            <a href="{{ route('admin.meetings.index') }}"
               class="admin-nav-link"
               @if(request()->routeIs('admin.meetings.*')) aria-current="page" @endif>
                <x-tabler-video aria-hidden="true" />
                <span>Reuniones</span>
            </a>

            <a href="{{ route('admin.files.index') }}"
               class="admin-nav-link"
               @if(request()->routeIs('admin.files.*')) aria-current="page" @endif>
                <x-tabler-folder aria-hidden="true" />
                <span>Archivos</span>
            </a>

            <a href="{{ route('admin.staff.index') }}"
               class="admin-nav-link"
               @if(request()->routeIs('admin.staff.*')) aria-current="page" @endif>
                <x-tabler-users aria-hidden="true" />
                <span>Equipo</span>
            </a>

        </nav>

        <div class="admin-account">
            {{-- Your own name is the way into your own account — the rail has
                 no room for a fifth nav entry, and this is where you look. --}}
            <a href="{{ route('admin.account') }}" class="admin-account-link"
               @if(request()->routeIs('admin.account')) aria-current="page" @endif>
                <span class="admin-avatar" aria-hidden="true">{{ $user->initials() }}</span>
                <span class="admin-account-meta">
                    <b>{{ $user->name }}</b>
                    <span>{{ $user->role->label() }}</span>
                </span>
            </a>
            <x-theme-toggle />
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="admin-logout">
                    <span class="screen-reader-only">Salir</span>
                    <x-tabler-logout aria-hidden="true" />
                </button>
            </form>
        </div>

    </aside>

    {{-- ----------------------------------------------------------------
         Content
         ---------------------------------------------------------------- --}}
    <div class="admin-column">

        <header class="admin-topbar">
            @if($heading)
                <h1 class="admin-heading">{{ $heading }}</h1>
            @else
                <span></span>
            @endif
            {{ $actions ?? '' }}
        </header>

        <main class="admin-main">
            @if (session('status'))
                {{-- Through __() because Fortify flashes a key rather than a
                     sentence — "password-updated". Our own controllers flash
                     Spanish, which passes through untouched. --}}
                <p class="admin-note admin-note-good" role="status">{{ __(session('status')) }}</p>
            @endif

            @if ($errors->any())
                <div class="admin-note admin-note-bad" role="alert">
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
