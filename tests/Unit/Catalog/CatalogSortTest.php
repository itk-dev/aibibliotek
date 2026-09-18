<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\CatalogSort;
use PHPUnit\Framework\TestCase;

final class CatalogSortTest extends TestCase
{
    // Verifies the default ordering is newest-first.
    public function testDefaultIsNewest(): void
    {
        self::assertSame(CatalogSort::Newest, CatalogSort::default());
    }

    // Tests that each defined query-string value resolves to its matching case.
    public function testFromStringResolvesEveryKnownValue(): void
    {
        self::assertSame(CatalogSort::Newest, CatalogSort::fromString('newest'));
        self::assertSame(CatalogSort::Oldest, CatalogSort::fromString('oldest'));
        self::assertSame(CatalogSort::RecentlyUpdated, CatalogSort::fromString('recently_updated'));
        self::assertSame(CatalogSort::NameAsc, CatalogSort::fromString('name'));
        self::assertSame(CatalogSort::NameDesc, CatalogSort::fromString('name_desc'));
    }

    // Ensures an unknown value and a null both collapse to the default ordering.
    public function testFromStringFallsBackToDefaultForUnknownOrNull(): void
    {
        self::assertSame(CatalogSort::default(), CatalogSort::fromString('bogus'));
        self::assertSame(CatalogSort::default(), CatalogSort::fromString(null));
    }

    // Verifies every case maps to its namespaced translation key.
    public function testLabelKeyForEveryCase(): void
    {
        self::assertSame('catalog.sort.newest', CatalogSort::Newest->labelKey());
        self::assertSame('catalog.sort.oldest', CatalogSort::Oldest->labelKey());
        self::assertSame('catalog.sort.recently_updated', CatalogSort::RecentlyUpdated->labelKey());
        self::assertSame('catalog.sort.name_asc', CatalogSort::NameAsc->labelKey());
        self::assertSame('catalog.sort.name_desc', CatalogSort::NameDesc->labelKey());
    }
}
