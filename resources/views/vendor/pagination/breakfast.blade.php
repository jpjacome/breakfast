{{-- Pagination on the Breakfast design system. Replaces Laravel's default
     Tailwind view, which we can't use since Tailwind was removed. --}}

@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="Paginación">

        @if ($paginator->onFirstPage())
            <span class="pager__link is-disabled" aria-disabled="true">Anterior</span>
        @else
            <a class="pager__link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Anterior</a>
        @endif

        <span class="pager__count bkf-tabular">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}
        </span>

        @if ($paginator->hasMorePages())
            <a class="pager__link" href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente</a>
        @else
            <span class="pager__link is-disabled" aria-disabled="true">Siguiente</span>
        @endif

    </nav>
@endif
