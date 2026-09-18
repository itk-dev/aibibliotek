<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pagination;

use App\Pagination\PageMetadata;
use Doctrine\ORM\Tools\Pagination\Paginator;
use PHPUnit\Framework\TestCase;

final class PageMetadataTest extends TestCase
{
    // Tests that page count is computed as ceil(total / perPage).
    public function testFromPaginatorComputesTotalAndPageCount(): void
    {
        $paginator = $this->createMock(Paginator::class);
        $paginator->method('count')->willReturn(25);

        $metadata = PageMetadata::fromPaginator($paginator, page: 2, perPage: 10);

        self::assertSame(25, $metadata->total);
        self::assertSame(10, $metadata->perPage);
        self::assertSame(2, $metadata->page);
        self::assertSame(3, $metadata->pageCount, '25 ÷ 10 rounds up to 3 pages');
    }

    // Ensures an empty result set still reports at least one page.
    public function testFromPaginatorFloorsPageCountToOneOnEmpty(): void
    {
        $paginator = $this->createMock(Paginator::class);
        $paginator->method('count')->willReturn(0);

        $metadata = PageMetadata::fromPaginator($paginator, page: 1, perPage: 10);

        self::assertSame(0, $metadata->total);
        self::assertSame(1, $metadata->pageCount, 'empty result still renders as "page 1 of 1"');
    }
}
