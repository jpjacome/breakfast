{{--
    $css: an extra stylesheet by name — css="permissions" loads
          resources/css/permissions.css. general.css and dashboard.css are the
          two every portal screen gets; anything else belongs to the page or
          component that asked for it and ships only with it. Same contract as
          <x-layouts.app>.
--}}
@props([
    'title' => null,
    'section' => null,
    'css' => null,
    'scripts' => [],
    /*
     * $conversations: the conversations route for this side, on a page that
     *           carries an assistant. Given, the history panel becomes a second
     *           full-height column between the rail and the content — see
     *           conversations.css.
     *
     *           ⚠️ IT IS A LAYOUT COLUMN AND NOT PART OF THE ASSISTANT, which is
     *           the whole reason it is a prop here rather than markup inside the
     *           panel. Inside the page it could only ever be as tall as the page's
     *           own content, and the list of a year's conversations is taller than
     *           that.
     *
     *           ⚠️ AND IT ONLY BELONGS WHERE AN ASSISTANT IS. conversations.js
     *           renders a conversation INTO [data-assistant-thread]; on a screen
     *           with no assistant every row in it would be a click that does
     *           nothing. Pass it from those pages only.
     */
    'conversations' => null,
    /*
     * A page name shown in the topbar, after the brand.
     *
     * ⚠️ FOR A PAGE THAT HAS NO ROOM FOR A HEADING, which today is the Brand
     * Egg: its two panes are locked to the viewport, so a title above them is
     * height taken straight out of the drawing. Everywhere else the heading
     * belongs on the page, where it can carry a sentence under it — this is
     * the exception, not a second place to put every title.
     */
    'topbarTitle' => null,
])

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
         twice. Same contract as the admin layout. --}}
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
        'resources/css/dashboard.css',
        'resources/css/header-bar.css',
        ...array_map(
            fn ($name) => "resources/css/{$name}.css",
            array_unique(array_filter([...(array) $css, $conversations ? 'conversations' : null])),
        ),
    ], $scripts))
</head>
@php
    /*
     * The brands this person can switch between — ACC-01/02.
     *
     * ⚠️ RESOLVED ONCE, HERE, because two places draw it now: the rail's own
     * list and the topbar's quick picker. Queried twice it would be two
     * queries per request that can disagree after a membership changes
     * mid-session.
     *
     * Breakfast staff get none. They can open /portal — the rail prints
     * "Breakfast" where a brand's name goes — but they are in no brand, so a
     * switcher would be a control with nothing to switch.
     */
    $brands = $user->isBreakfast()
        ? collect()
        : $user->brands()->orderBy('name')->get();
@endphp

<body class="dashboard">

<a href="#contenido" class="skip-link">Saltar al contenido</a>

{{-- Checkbox toggle, same no-JavaScript pattern as the public navbar. --}}
<input type="checkbox" id="sidebar-toggle" class="dashboard-toggle" hidden>

{{-- ⚠️ ABOVE THE FRAME, NOT INSIDE IT. It spans the rail and the content
     alike, and the frame is the two-column grid underneath it. Its height is
     --header-bar-height, which is what the columns below subtract from the
     viewport. --}}
{{-- ⚠️ THE ROLE IS THE ONE IN THE ACTIVE BRAND, not on the account (ACC-01).
     The same person can own one brand and be a member of another, so
     users.role would print "Miembro" to somebody looking at the brand they
     own. Breakfast staff are in no brand at all and fall back to theirs. --}}
<x-header-bar :home="route('portal.home')"
              :account="route('portal.perfil')"
              account-label="Perfil"
              :role-label="$user->brandRole()?->label() ?? $user->role->label()" />

<div class="dashboard-frame">

    <aside class="dashboard-sidebar">

        {{-- ⚠️ THE LOGO IS NOT HERE ANY MORE — it is in the header bar above
             the frame, and the same wordmark at the top of the bar and at the
             top of the rail is one mark drawn twice on one screen.

             What is left is the close button, which belongs to the rail rather
             than to the logo: it only exists in the slide-over, where the rail
             is the whole screen and has to be dismissable. The block keeps its
             name because the stylesheet lays it out. --}}
        <div class="dashboard-wordmark">
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
        {{--
            THEIR BRANDS, above the sections — ACC-01.

            ⚠️ IT IS THE MENU'S JOB TO SAY THEY HAVE MORE THAN ONE. The topbar
            has had a picker since ACC-02, but a picker is a control you have
            to already suspect is there: it looks like a label until you click
            it. Somebody who owns two brands and never noticed has half a
            portal they do not know about, and this is the list that tells them.

            ⚠️ ONE BRAND, NO LIST. A menu group holding a single row that
            reloads the page you are on is furniture. Breakfast staff get none
            either — they are in no brand.

            Plain forms, no JavaScript: switching is a POST because it changes
            what the session is about, and this side ships no script for the
            rail.
        --}}
        @if ($brands->count() > 1)
            <nav class="dashboard-brands" aria-label="Tus marcas">
                <p class="dashboard-brands-title">Tus marcas</p>

                @foreach ($brands as $brand)
                    @php $isHere = $brand->is($user->activeBrand()); @endphp

                    <form method="POST" action="{{ route('portal.brand.switch', $brand) }}">
                        @csrf
                        {{--
                            ⚠️ ONE class ATTRIBUTE, NOT TWO. This had a literal
                            class="…" AND a @ class([…]) beside it, which
                            compiles to a SECOND class attribute — and an HTML
                            parser keeps the first and silently drops the rest,
                            so `is-current` never reached the browser and the
                            current brand was never marked. Nothing errors: the
                            attribute is simply gone by the time anything can
                            read it. The conditional class belongs INSIDE the
                            one attribute.
                        --}}
                        <button type="submit"
                                class="dashboard-nav-link dashboard-brands-link {{ $isHere ? 'is-current' : '' }}"
                                @if ($isHere) aria-current="true" @endif>
                            {{-- A tick on the one you are in, a dot on the
                                 rest. Weight alone is a fair marker but a
                                 quiet one, and the rail is scanned rather than
                                 read. --}}
                            @if ($isHere)
                                <x-tabler-circle-check aria-hidden="true" />
                            @else
                                <x-tabler-circle-dot aria-hidden="true" />
                            @endif
                            <span>{{ $brand->name }}</span>
                        </button>
                    </form>
                @endforeach
            </nav>
        @endif

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

        {{-- ⚠️ THE ACCOUNT BLOCK IS GONE FROM HERE. It was avatar, name,
             role, theme and salir at the foot of the rail; all five are the
             pill in the header bar now. Keeping both meant the same person's
             account answered twice on one screen, and the role line — the one
             thing the pill did not carry — moved up with the rest. --}}

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

            {{-- Where the page names itself when it cannot afford a heading.
                 Reads as "Marca · Brand Egg", so the brand stays the subject
                 and the page is the qualifier. --}}
            @if ($topbarTitle)
                <p class="dashboard-topbar-title">{{ $topbarTitle }}</p>
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

    {{-- Tapping the dimmed page closes the menu on small screens. --}}
    <label for="sidebar-toggle" class="dashboard-scrim"></label>

</div>

</body>
</html>
