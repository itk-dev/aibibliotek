<?php

declare(strict_types=1);

namespace App\Pagination;

use Doctrine\ORM\Tools\Pagination\Paginator;

final class PageMetadata
{
    /**
     * Read-only snapshot of a paginated view's state.
     *
     * Carries the four numbers a template needs to render its
     * "N resultater" count, current-page badge, and page navigation.
     *
     * @param int $total     total matching rows across the whole result set
     * @param int $perPage   page size used to compute `$pageCount`
     * @param int $page      current 1-based page number (clamped by the caller)
     * @param int $pageCount total number of pages; always `>= 1`, even when `$total === 0`
     */
    public function __construct(
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $page,
        public readonly int $pageCount,
    ) {
    }

    /**
     * Named constructor — derive page metadata from a Doctrine `Paginator`.
     *
     * Reads the total row count (Paginator issues the COUNT query lazily
     * on first access), divides by `$perPage` to get the page count, and
     * floors that to a minimum of 1 so empty-result views still render
     * "page 1 of 1" rather than "page 1 of 0".
     *
     * @param Paginator<object> $paginator the executed paginator whose total is needed
     * @param int               $page      the 1-based page the caller asked for; passed through verbatim
     * @param int               $perPage   the page size used when querying; must be `>= 1`
     *
     * @return self immutable read-only view metadata
     */
    public static function fromPaginator(Paginator $paginator, int $page, int $perPage): self
    {
        $total = \count($paginator);
        $pageCount = max(1, (int) ceil($total / $perPage));

        return new self($total, $perPage, $page, $pageCount);
    }
}
