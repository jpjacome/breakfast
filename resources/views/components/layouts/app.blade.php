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

    $conversations: the conversations route for this side, on a page that
              carries an assistant. Given, the history panel becomes a second
              full-height column between the rail and the content — see
              conversations.css.

              ⚠️ IT IS A LAYOUT COLUMN AND NOT PART OF THE ASSISTANT, which is
              the whole reason it is a prop here rather than markup inside the
              panel. Inside the page it could only ever be as tall as the page's
              own content, and the list of a year's conversations is taller than
              that.

              ⚠️ AND IT ONLY BELONGS WHERE AN ASSISTANT IS. conversations.js
              renders a conversation INTO [data-assistant-thread]; on a screen
              with no assistant every row in it would be a click that does
              nothing. Pass it from those pages only.
--}}
@props([
    'title' => null,
    'heading' => null,
    'css' => null,
    'scripts' => [],
    'conversations' => null,
])

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

    {{--
        ⚠️ THE CONVERSATIONS PROP BRINGS ITS OWN STYLESHEET. Asking for the
        history rail and forgetting to name it in :css would draw it as raw
        HTML down the side of the screen, which is exactly how permissions.css
        broke — so the prop carries the file rather than trusting every page.

        ⚠️ AND THE EXPLANATION LIVES OUT HERE, ABOVE THE CALL. A directive
        argument is not a raw PHP region: Blade re-emits it, so a line comment
        inside it takes an apostrophe as the start of a string, and a component
        tag written with angle brackets is compiled into a real component. Both
        turn this page into a parse error or a printout of its own source.
        Trap 11, twice over. Keep the argument bare.
    --}}
    @vite(array_merge([
        'resources/css/general.css',
        'resources/css/admin.css',
        'resources/css/header-bar.css',
        ...array_map(
            fn ($name) => "resources/css/{$name}.css",
            array_unique(array_filter([...(array) $css, $conversations ? 'conversations' : null])),
        ),
    ], $scripts))
</head>
<body class="admin">

{{-- ⚠️ ABOVE THE FRAME, NOT INSIDE IT. It spans the rail and the content
     alike, and the frame is the two-column grid underneath it. Its height is
     --header-bar-height, which is what the columns below subtract from the
     viewport. --}}
<x-header-bar :home="route('admin.home')"
              :account="route('admin.account')"
              account-label="Cuenta"
              :role-label="$user->role->label()" />

<div class="admin-frame">

    {{-- ----------------------------------------------------------------
         Left rail
         ---------------------------------------------------------------- --}}
    <aside class="admin-sidebar">

        {{-- ⚠️ THE LOGO IS NOT HERE ANY MORE — it is in the header bar above
             the frame. The same wordmark at the top of the bar and at the top
             of the rail is one mark drawn twice on one screen. --}}

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

        {{-- ⚠️ THE ACCOUNT BLOCK IS GONE FROM HERE. It was avatar, name,
             role, theme and salir at the foot of the rail; all five are the
             pill in the header bar now. Keeping both meant the same person's
             account answered twice on one screen, and the role line — the one
             thing the pill did not carry — moved up with the rest. --}}

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

    {{-- ----------------------------------------------------------------
         The history panel, where the page asked for one — a rail down the
         RIGHT edge, full height like the navigation on the left.

         ⚠️ LAST IN THE SOURCE, not placed by a grid column. On a narrow screen
         the frame drops to one column and DOM order is what decides where this
         lands; the stylesheet pulls it back above the content there, because a
         history below a long dashboard is a history nobody scrolls to.
         ---------------------------------------------------------------- --}}
    @if ($conversations)
        <x-conversations-panel :base="$conversations" />
    @endif

</div>

</body>
</html>
