{{--
    THE HEADER BAR — the yellow strip across the top of both shells.

    ⚠️ IT IS A COMPONENT, NOT PART OF EITHER SHELL, for the same reason
    permissions.css and conversations.css are: it is drawn on /admin AND on
    /portal, so neither admin.css nor dashboard.css can own it. Its stylesheet
    is header-bar.css and both layouts name it in their own @vite call.

    ⚠️ IT IS ALSO WHERE THE BREAKFAST LOGO LIVES NOW. It used to sit at the top
    of each rail (.dashboard-wordmark, .admin-logo); a wordmark in a bar above
    the rail AND at the top of the rail is the same mark twice on one screen,
    so the rails gave it up when this arrived.

    $home:  where the logo goes back to — portal.home on one side, admin.home
            on the other. The component has no way to know which shell is
            drawing it and does not try to guess.
    $roleLabel: what you ARE, under your name in the menu. ⚠️ On /portal that
            is your role IN THE ACTIVE BRAND (ACC-01), not on the account — the
            same person can own one brand and be a member of another — so the
            layout works it out and this only prints it.
    $account, $accountLabel: your own account, and what THAT SIDE calls it —
            "Perfil" on /portal (PortalSection::Perfil), "Cuenta" on /admin
            (/admin/cuenta). Both a URL and a word because one page with two
            names is already true across these shells, and the pill saying
            "Cuenta" while the rail beside it says "Perfil" would be two names
            for one page on one screen.
--}}
@props(['home', 'account', 'accountLabel', 'roleLabel'])

@php $user = auth()->user(); @endphp

<header class="header-bar">

    <a href="{{ $home }}" class="header-bar-logo" aria-label="Breakfast">
        {{-- logo-2, not logo: the plain black wordmark rather than the one
             wearing its yellow sticker. The bar IS the yellow plane now, so
             the sticker would be a yellow patch on yellow with a keyline
             round it. --}}
        <img src="{{ asset('img/logo-2.png') }}" alt="Breakfast" width="2529" height="444">
    </a>

    <div class="header-bar-controls">

    {{--
        The light/dark switch, beside the pill.

        ⚠️ IT IS RENDERED EXACTLY ONCE PER PAGE and this is now the once. The
        component carries its own <script> in its body, so a second one on a
        screen would bind every button twice and a click would flip the theme
        and flip it straight back.
    --}}
    <x-theme-toggle />

    {{--
        Who is signed in, and everything you do with that: see who you are, go
        to your own account, and leave.

        ⚠️ THIS IS THE WHOLE ACCOUNT BLOCK NOW. The rails had one at their foot
        — avatar, name, role, theme, salir — and it is gone from both: with the
        pill up here it was the same person's account answered twice on one
        screen. The name and the role came up here with it, which is why the
        menu opens with them rather than going straight to the two actions.

        A details/summary rather than a JavaScript popover, the same mechanism
        as the bell and the brand picker below it. Neither shell ships script
        for its furniture, and the browser gives us the toggle, Escape and the
        focus handling for free.

        firstName() rather than name(): "Hola, María" is a greeting and
        "Hola, María Fernanda Salazar Vega" is a form. It can be null, and
        then the pill greets nobody rather than printing "Hola, ". The menu
        under it prints the whole name, which is where the long one belongs.
    --}}
    <details class="header-bar-user">
        <summary>
            <span class="header-bar-avatar" aria-hidden="true">
                <x-tabler-user />
            </span>
            <span class="header-bar-greeting">
                {{ $user->firstName() ? 'Hola, '.$user->firstName() : $user->name }}
            </span>
            <x-tabler-chevron-down class="header-bar-chevron" aria-hidden="true" />
            <span class="screen-reader-only">Abrir el menú de tu cuenta</span>
        </summary>

        <div class="header-bar-menu">
            {{-- Not a row you can click: it says who the menu is about. --}}
            <p class="header-bar-who">
                <b>{{ $user->name }}</b>
                <span>{{ $roleLabel }}</span>
            </p>

            <a href="{{ $account }}">
                <x-tabler-user-circle aria-hidden="true" />
                <span>{{ $accountLabel }}</span>
            </a>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">
                    <x-tabler-logout aria-hidden="true" />
                    <span>Salir</span>
                </button>
            </form>
        </div>
    </details>

    </div>

</header>
