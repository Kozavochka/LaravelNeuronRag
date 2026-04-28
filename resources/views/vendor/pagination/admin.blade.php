@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation" class="admin-pager">
        <div class="admin-pager-meta">
            Showing
            @if ($paginator->firstItem())
                <strong>{{ $paginator->firstItem() }}</strong>
                to
                <strong>{{ $paginator->lastItem() }}</strong>
            @else
                <strong>{{ $paginator->count() }}</strong>
            @endif
            of
            <strong>{{ $paginator->total() }}</strong>
            results
        </div>

        <div class="admin-pager-links">
            @if ($paginator->onFirstPage())
                <span class="is-disabled">&lsaquo;</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous">&lsaquo;</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="is-ellipsis">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="is-current">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next">&rsaquo;</a>
            @else
                <span class="is-disabled">&rsaquo;</span>
            @endif
        </div>
    </nav>
@endif
