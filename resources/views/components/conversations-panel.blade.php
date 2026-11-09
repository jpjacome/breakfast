{{--
    The history side panel — item 5.

    ⚠️ ONE COMPONENT, BOTH SHELLS, so it gets its own stylesheet
    (`conversations.css`) named by every page that renders it — the same
    contract permissions.css and auth.css follow (CLAUDE.md §9). It may only
    use tokens BOTH shells define: `--page`, `--ink` and the `--bkf-*` palette.
    A token only the portal has renders this unstyled on the admin side, which
    is exactly how permissions.css broke.

    ⚠️ THE MARKUP IS A SHELL. Everything inside `[data-conversations-body]` is
    drawn by conversations.js from the server's own list — rendering the rows
    here as well would mean two drawings of the same rows, and the one on load
    would go stale the moment anything moved.

    Props:
      base  the conversations route for THIS surface. The component never picks
            it: which side of the app this is was decided by the middleware the
            route is already behind.
--}}
@props(['base'])

<aside class="conversations" data-conversations data-base="{{ $base }}"
       aria-label="Conversaciones guardadas">

    <div class="conversations-head">
        <button type="button" class="conversations-toggle" data-conversations-toggle
                aria-expanded="true" title="Mostrar u ocultar el historial">
            <span class="screen-reader-only">Mostrar u ocultar el historial</span>
            <x-tabler-layout-sidebar aria-hidden="true" />
        </button>

        <p class="conversations-title">Conversaciones</p>

        <button type="button" class="conversations-action" data-conversations-new-folder
                title="Nueva carpeta">
            <span class="screen-reader-only">Nueva carpeta</span>
            <x-tabler-folder-plus aria-hidden="true" />
        </button>

        <button type="button" class="conversations-action" data-conversations-new
                title="Nueva conversación">
            <span class="screen-reader-only">Nueva conversación</span>
            <x-tabler-plus aria-hidden="true" />
        </button>
    </div>

    <div class="conversations-body" data-conversations-body></div>

    {{-- Archived is "estoy listo con esto", not "bórralo": out of the list by
         default, whole, and one click away. --}}
    <details class="conversations-archive">
        <summary>Archivadas</summary>
        <div data-conversations-archived></div>
    </details>

</aside>
