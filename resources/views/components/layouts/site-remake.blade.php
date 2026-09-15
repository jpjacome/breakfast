@props([
    'title' => null,
    'description' => 'Consultora de procesos creativos. Creamos marcas, diseñamos servicios y proponemos nuevos productos.',
    {{-- The page's own stylesheet, named without the folder or extension:
         css="nosotros" loads resources/css/nosotros.css beside general.css.
         A page with no styles of its own leaves it out.

         Takes a LIST as well as a name: :css="['auth', 'login']" — same terms
         as <x-layouts.app>. A page owns one stylesheet, but it may also render
         something that brings its own: the six entrance screens share one
         auth.css, and /login names it alongside its own file. --}}
    'css' => null,
    {{-- Login and the other account screens are part of the site but should not
         be indexed or shared as a link preview. --}}
    'noindex' => false,
])

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title ? $title.' — Breakfast' : 'Breakfast' }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ url()->current() }}">

    @if ($noindex)
        <meta name="robots" content="noindex, nofollow">
    @endif

    <meta property="og:site_name" content="Breakfast">
    <meta property="og:title" content="{{ $title ?? 'Breakfast' }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:image" content="{{ asset('img/logo.png') }}">

    <link rel="icon" href="{{ asset('img/favicon.png') }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('img/favicon.png') }}">

    {{-- Runs before first paint. Elements that animate in on load are hidden
         only when this class is present, so with JS off they stay visible
         rather than never appearing at all. --}}
    <script>document.documentElement.classList.add('js')</script>

    {{ Vite::fonts() }}
    @vite([
        'resources/css/general.css',
        'resources/css/layout.css',
        ...array_map(fn ($name) => "resources/css/{$name}.css", array_filter((array) $css)),
        'resources/js/app.js',
    ])
</head>
<body class="website">

<a href="#contenido" class="skip-link">Saltar al contenido</a>

{{-- Same four destinations as the live site. Nothing added. --}}
<header class="navbar">
    <div class="navbar-inner">

        {{-- logo-2: the plain black wordmark. The yellow-sticker lockup
             (logo-1) would sit yellow-on-yellow against this header. --}}
        <a href="{{ route('home') }}" class="navbar-logo" aria-label="Breakfast — inicio">
            <img src="{{ asset('img/logo-2.png') }}" alt="Breakfast" width="2529" height="444">
        </a>

        <input type="checkbox" id="navbar-toggle" class="navbar-toggle" hidden>
        <label for="navbar-toggle" class="navbar-burger">
            <span class="screen-reader-only">Abrir menú</span>
            <span class="navbar-burger-bar" aria-hidden="true"></span>
            <span class="navbar-burger-bar" aria-hidden="true"></span>
        </label>

        <nav class="navbar-links" aria-label="Principal">
            <a href="{{ route('nosotros') }}">Nosotros</a>
            <a href="{{ route('servicios') }}">Servicios</a>
            <a href="{{ route('podcast') }}">Latest Podcast</a>
            <a href="{{ route('contacto') }}" class="navbar-cta">Contáctanos</a>

            {{-- Portal entrance. Points at the visitor's own dashboard once they
                 are signed in: /login would only bounce them straight back out
                 again, which reads as a dead link.

                 An icon on its own carries no text for a screen reader, so the
                 label is spelled out and the glyph hidden from the
                 accessibility tree. --}}
            @auth
                <a href="{{ auth()->user()->homeRoute() }}" class="navbar-account">
                    <x-tabler-user aria-hidden="true" />
                    <span class="navbar-account-label">Ir a mi portal</span>
                </a>
            @else
                <a href="{{ route('login') }}" class="navbar-account">
                    <x-tabler-user aria-hidden="true" />
                    <span class="navbar-account-label">Entrar al portal</span>
                </a>
            @endauth
        </nav>

    </div>
</header>

<main id="contenido">
    {{ $slot }}
</main>

<footer class="footer">
    <div class="container">
        <p class="footer-wordmark">Breakfast<sup>&reg;</sup></p>
        <p class="footer-note">We work worldwide.</p>
    </div>
</footer>

</body>
</html>
