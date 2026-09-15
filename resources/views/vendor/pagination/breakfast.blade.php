{{-- Pagination on the Breakfast admin styles. Replaces Laravel's default
     Tailwind view, which we can't use since Tailwind was removed. --}}

@if ($paginator->hasPages())
    <nav class="admin-pager" role="navigation" aria-label="Paginación">

        @if ($paginator->onFirstPage())
            <span class="admin-pager-link is-disabled" aria-disabled="true">Anterior</span>
        @else
            <a class="admin-pager-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Anterior</a>
        @endif

        <span class="admin-pager-count admin-numbers">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}
        </span>

        @if ($paginator->hasMorePages())
            <a class="admin-pager-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente</a>
        @else
            <span class="admin-pager-link is-disabled" aria-disabled="true">Siguiente</span>
        @endif

    </nav>
@endif
