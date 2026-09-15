{{--
    $css: an extra stylesheet by name — css="permissions" loads
          resources/css/permissions.css. general.css and dashboard.css are the
          two every portal screen gets; anything else belongs to the page or
          component that asked for it and ships only with it. Same contract as
          <x-layouts.app>.
--}}
@props(['title' => null, 'section' => null, 'css' => null, 'scripts' => []])

@php
    use App\Enums\PortalSection;

    $user = auth()->user();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ? $title.' · Portal Breakfast' : 'Portal Breakfast' }}</title>

    <link rel="icon" href="{{ asset('img/favicon.png') }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('img/favicon.png') }}">
    {{ Vite::fonts() }}

    {{-- Above the stylesheet on purpose — see the component. --}}
    <x-theme-boot />

    {{-- One @vite call, never two: calling it twice emits the dev client
         twice. Same contract as <x-layouts.app>. --}}
    @vite(array_merge([
        'resources/css/general.css',
        'resources/css/dashboard.css',
        ...array_map(fn ($name) => "resources/css/{$name}.css", array_filter((array) $css)),
    ], $scripts))
</head>
<body class="dashboard">

<a href="#contenido" class="skip-link">Saltar al contenido</a>

{{-- Checkbox toggle, same no-JavaScript pattern as the public navbar. --}}
<input type="checkbox" id="sidebar-toggle" class="dashboard-toggle" hidden>

<div class="dashboard-frame">

    <aside class="dashboard-sidebar">

        <div class="dashboard-brand">
            <a href="{{ route('portal.home') }}" aria-label="Portal Breakfast">
                <img src="{{ asset('img/logo.png') }}" alt="Breakfast" width="795" height="139">
            </a>
            <label for="sidebar-toggle" class="dashboard-close">
                <span class="screen-reader-only">Cerrar menú</span>
                <x-tabler-x aria-hidden="true" />
            </label>
        </div>

        {{--
            A flat list, no headings. With five entries a heading over each pair
            was furniture rather than navigation.

            Built from what this user can actually reach: a section they were
            not given is absent, not greyed out, because a disabled link to
            Reuniones still tells them their brand holds meetings. The route
            middleware refuses the URL either way.

            No read-only badge either. Every section on this side is read-only —
            Breakfast writes a brand, the brand reads it — so an eye on all of
            them marked nothing apart from anything else.
        --}}
        <nav class="dashboard-nav" aria-label="Portal">

            {{-- The dashboard itself, which is not a PortalSection: it is
                 ungated, it is where the assistant lives, and its route is
                 portal.home rather than portal.{value}. Same shape as the
                 Breakfast side, where Inicio is also a plain link. --}}
            <a href="{{ route('portal.home') }}"
               class="dashboard-nav-link"
               @if ($section === null) aria-current="page" @endif>
                <x-tabler-home aria-hidden="true" />
                <span>Inicio</span>
            </a>

            @foreach (PortalSection::cases() as $item)
                @continue (! $user->canRead($item))

                <a href="{{ route($item->routeName()) }}"
                   class="dashboard-nav-link"
                   @if ($section === $item) aria-current="page" @endif>
                    <x-dynamic-component :component="'tabler-'.$item->icon()" aria-hidden="true" />
                    <span>{{ $item->label() }}</span>
                </a>
            @endforeach
        </nav>

        <div class="dashboard-account">
            <span class="dashboard-avatar" aria-hidden="true">{{ $user->initials() }}</span>
            <span class="dashboard-account-meta">
                <b>{{ $user->name }}</b>
                {{--
                    ⚠️ The role IN THIS BRAND, not on the account (ACC-01). The
                    same person can own one brand and be a member of another,
                    so users.role cannot answer this any more — it would print
                    "Miembro" to somebody looking at the brand they own.
                --}}
                <span>{{ $user->brandRole()?->label() ?? $user->role->label() }}</span>
            </span>
            <x-theme-toggle />
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="dashboard-logout">
                    <span class="screen-reader-only">Salir</span>
                    <x-tabler-logout aria-hidden="true" />
                </button>
            </form>
        </div>

    </aside>

    <div class="dashboard-column">

        <header class="dashboard-topbar">
            <label for="sidebar-toggle" class="dashboard-burger">
                <span class="screen-reader-only">Abrir menú</span>
                <x-tabler-menu-2 aria-hidden="true" />
            </label>
            {{--
                Which brand you are in — ACC-02.

                One brand, or Breakfast staff: just the name, because a picker
                with nothing to pick is a control that lies about having a
                choice. Two or more: a real picker.

                A details/summary again, for the same reason as the bell below
                it — the portal ships no JavaScript for this, and the browser
                gives us the toggle, Escape and focus handling for free.
            --}}
            @php $brands = $user->isBreakfast() ? collect() : $user->brands()->orderBy('name')->get(); @endphp

            @if ($brands->count() > 1)
                <details class="dashboard-brand">
                    <summary>
                        <span class="dashboard-client">{{ $user->activeBrand()?->name }}</span>
                        <x-tabler-chevron-down aria-hidden="true" />
                        <span class="screen-reader-only">Cambiar de marca</span>
                    </summary>

                    <div class="dashboard-brand-menu">
                        @foreach ($brands as $brand)
                            <form method="POST" action="{{ route('portal.brand.switch', $brand) }}">
                                @csrf
                                <button type="submit"
                                        @class(['is-current' => $brand->is($user->activeBrand())])
                                        @if ($brand->is($user->activeBrand())) aria-current="true" @endif>
                                    {{ $brand->name }}
                                </button>
                            </form>
                        @endforeach
                    </div>
                </details>
            @else
                <p class="dashboard-client">{{ $user->activeBrand()?->name ?? 'Breakfast' }}</p>
            @endif

            {{--
                The inbox. No section gate: notifications belong to the user,
                and what lands in one was already filtered by who could see the
                thing it is about — see Client::meetingAudience().

                A details/summary rather than a JavaScript popover, because the
                portal ships no JavaScript and this does not need any: the
                browser gives us the toggle, the escape key and the focus
                handling for free.
            --}}
            @php $unread = $user->unreadNotifications; @endphp

            <details class="dashboard-bell">
                <summary>
                    <x-tabler-bell aria-hidden="true" />
                    <span class="screen-reader-only">
                        Avisos{{ $unread->count() ? " ({$unread->count()} sin leer)" : '' }}
                    </span>
                    @if ($unread->isNotEmpty())
                        <span class="dashboard-bell-count" aria-hidden="true">{{ $unread->count() }}</span>
                    @endif
                </summary>

                <div class="dashboard-bell-panel">
                    @forelse ($unread as $note)
                        <a href="{{ $note->data['url'] ?? route('portal.home') }}" class="dashboard-bell-item">
                            <b>{{ $note->data['headline'] ?? 'Aviso' }}</b>
                            @isset($note->data['when'])
                                <span>{{ ucfirst($note->data['when']) }}</span>
                            @endisset
                            <span class="dashboard-bell-ago">{{ $note->created_at->diffForHumans() }}</span>
                        </a>
                    @empty
                        <p class="dashboard-bell-empty">No tienes avisos nuevos.</p>
                    @endforelse

                    @if ($unread->isNotEmpty())
                        <form method="POST" action="{{ route('portal.notifications.read') }}">
                            @csrf
                            <button type="submit" class="dashboard-bell-clear">Marcar todo como leído</button>
                        </form>
                    @endif
                </div>
            </details>
        </header>

        <main id="contenido" class="dashboard-main">

            @if (session('status'))
                {{-- Through __() because Fortify flashes a key rather than a
                     sentence — "password-updated", from the profile and
                     password forms on /portal/perfil. Our own controllers flash
                     Spanish, which passes through untouched. Same as the admin
                     shell does. --}}
                <p class="dashboard-note" role="status">{{ __(session('status')) }}</p>
            @endif

            @if ($errors->any())
                <div class="dashboard-note dashboard-note-problem" role="alert">
                    @if ($errors->count() === 1)
                        {{ $errors->first() }}
                    @else
                        <strong>Revisa lo siguiente:</strong>
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            {{ $slot }}
        </main>

    </div>

    {{-- Tapping the dimmed page closes the menu on small screens. --}}
    <label for="sidebar-toggle" class="dashboard-scrim"></label>

</div>

</body>
</html>
