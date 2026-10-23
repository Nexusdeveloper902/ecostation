@props(['paginator', 'label' => 'registros', 'loadMore' => false])

@php
    $first = $paginator->firstItem();
    $last = $paginator->lastItem();
@endphp

<nav class="pagination" aria-label="Paginación de {{ $label }}">
    <p class="pagination__status">
        @if ($paginator->total() === 0)
            Sin {{ $label }} todavía
        @else
            Mostrando {{ number_format($first, 0, ',', '.') }}–{{ number_format($last, 0, ',', '.') }}
            de {{ number_format($paginator->total(), 0, ',', '.') }} {{ $label }}
        @endif
    </p>

    @if ($paginator->hasPages())
        @if ($loadMore)
            @if ($paginator->hasMorePages())
                <a class="btn btn--outline" href="{{ $paginator->nextPageUrl() }}" rel="next">Cargar más</a>
            @endif
        @else
            <div class="pagination__links">
                @if ($paginator->onFirstPage())
                    <span class="pagination__link" aria-disabled="true" aria-label="Página anterior">
                        <x-icon name="arrow-left" :size="18" />
                    </span>
                @else
                    <a class="pagination__link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Página anterior">
                        <x-icon name="arrow-left" :size="18" />
                    </a>
                @endif

                @foreach ($paginator->getUrlRange(max(1, $paginator->currentPage() - 1), min($paginator->lastPage(), $paginator->currentPage() + 1)) as $page => $url)
                    @if ($page === $paginator->currentPage())
                        <span class="pagination__link" aria-current="page" aria-label="Página {{ $page }}">{{ $page }}</span>
                    @else
                        <a class="pagination__link" href="{{ $url }}" aria-label="Página {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <a class="pagination__link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Página siguiente">
                        <x-icon name="arrow-right" :size="18" />
                    </a>
                @else
                    <span class="pagination__link" aria-disabled="true" aria-label="Página siguiente">
                        <x-icon name="arrow-right" :size="18" />
                    </span>
                @endif
            </div>
        @endif
    @endif
</nav>
