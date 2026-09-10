@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Pagination">
        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span class="pagination__link is-disabled" aria-disabled="true" aria-label="@lang('pagination.previous')">&laquo;</span>
        @else
            <a class="pagination__link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="@lang('pagination.previous')">&laquo;</a>
        @endif

        {{-- Page numbers --}}
        @foreach ($elements as $element)
            {{-- "Three dots" separator --}}
            @if (is_string($element))
                <span class="pagination__link is-disabled" aria-disabled="true">{{ $element }}</span>
            @endif

            {{-- Array of links --}}
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pagination__link is-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="pagination__link" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a class="pagination__link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="@lang('pagination.next')">&raquo;</a>
        @else
            <span class="pagination__link is-disabled" aria-disabled="true" aria-label="@lang('pagination.next')">&raquo;</span>
        @endif
    </nav>

    <p class="pagination__summary">
        Showing {{ $paginator->firstItem() ?? 0 }} to {{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }} results
    </p>
@endif
