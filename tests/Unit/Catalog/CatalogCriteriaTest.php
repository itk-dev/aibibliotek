<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\CatalogCriteria;
use App\Catalog\CatalogSort;
use App\Http\QueryStringList;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CatalogCriteriaTest extends TestCase
{
    private QueryStringList $lists;

    protected function setUp(): void
    {
        $this->lists = new QueryStringList();
    }

    // Tests that an empty request produces a criteria with no `q`, empty facets, and all helpers reporting "nothing set".
    public function testFromRequestWithEmptyQueryReturnsEmptyCriteria(): void
    {
        $criteria = CatalogCriteria::fromRequest(Request::create('/search'), $this->lists);

        self::assertNull($criteria->q);
        self::assertSame([], $criteria->languageModels);
        self::assertSame([], $criteria->frameworks);
        self::assertSame([], $criteria->tags);
        self::assertTrue($criteria->isEmpty());
        self::assertSame([], $criteria->activeFilters());
        self::assertSame([], $criteria->toQueryArray());
    }

    // Ensures a whitespace-only `?q=` is normalised to null.
    public function testFromRequestNormalisesWhitespaceQToNull(): void
    {
        $criteria = CatalogCriteria::fromRequest(
            Request::create('/search', 'GET', ['q' => '   ']),
            $this->lists,
        );

        self::assertNull($criteria->q, 'whitespace-only `q` must normalise to null');
        self::assertTrue($criteria->isEmpty());
    }

    // Verifies that a populated request parses into `q` plus all facet lists and round-trips through toQueryArray().
    public function testFromRequestReadsQAndAllFacets(): void
    {
        $criteria = CatalogCriteria::fromRequest(
            Request::create('/search', 'GET', [
                'q' => 'borger',
                'language_model' => ['gpt-4o'],
                'framework' => ['openwebui'],
                'tag' => ['jura', 'social'],
            ]),
            $this->lists,
        );

        self::assertSame('borger', $criteria->q);
        self::assertSame(['gpt-4o'], $criteria->languageModels);
        self::assertSame(['openwebui'], $criteria->frameworks);
        self::assertSame(['jura', 'social'], $criteria->tags);
        self::assertFalse($criteria->isEmpty());
        self::assertSame(
            [
                'q' => 'borger',
                'language_model' => ['gpt-4o'],
                'framework' => ['openwebui'],
                'tag' => ['jura', 'social'],
            ],
            $criteria->toQueryArray(),
        );
    }

    // Ensures a request carrying only `?tag[]=` is non-empty and narrows on the tag facet alone.
    public function testFromRequestReadsTagsOnly(): void
    {
        $criteria = CatalogCriteria::fromRequest(
            Request::create('/search', 'GET', ['tag' => ['jura']]),
            $this->lists,
        );

        self::assertSame(['jura'], $criteria->tags);
        self::assertFalse($criteria->isEmpty());
        self::assertSame(['tag' => ['jura']], $criteria->toQueryArray());
    }

    // Tests that activeFilters() yields chips in fixed order — `q`, then language models, frameworks, then tags — with the search-query label quoted.
    public function testActiveFiltersYieldsQThenLanguageModelsThenFrameworksThenTags(): void
    {
        $criteria = new CatalogCriteria(
            q: 'borger',
            languageModels: ['gpt-4o', 'mistral-large'],
            frameworks: ['openwebui'],
            tags: ['jura'],
        );

        $filters = $criteria->activeFilters();

        self::assertCount(5, $filters);
        self::assertSame(['q', 'language_model', 'language_model', 'framework', 'tag'], array_map(
            static fn ($f) => $f->type,
            $filters,
        ));
        self::assertSame('borger', $filters[0]->value);
        self::assertSame('"borger"', $filters[0]->label, 'search-query chip wraps the value in quotes');
        self::assertSame('gpt-4o', $filters[1]->value);
        self::assertSame('gpt-4o', $filters[1]->label, 'facet chips display the raw value');
        self::assertSame('jura', $filters[4]->value);
        self::assertSame('jura', $filters[4]->label, 'tag chips display the raw tag name');
    }

    // Ensures a tag chip's removeQuery drops only that tag while preserving the search query and the other tags.
    public function testTagChipRemoveQueryDropsOnlyTargetedTag(): void
    {
        $criteria = new CatalogCriteria(q: 'borger', tags: ['jura', 'social']);

        $filters = $criteria->activeFilters();

        // The tag chips follow the `q` chip, so index 1 targets 'jura'.
        self::assertSame('jura', $filters[1]->value);
        self::assertSame(
            ['q' => 'borger', 'tag' => ['social']],
            $filters[1]->removeQuery,
        );
    }

    // Ensures a chip's removeQuery drops only its own value while preserving siblings on the same facet and the other facets.
    public function testActiveFilterRemoveQueryDropsOnlyTargetedValue(): void
    {
        $criteria = new CatalogCriteria(
            languageModels: ['gpt-4o', 'mistral-large'],
            frameworks: ['openwebui'],
        );

        $filters = $criteria->activeFilters();

        // First chip targets 'gpt-4o' — removeQuery must keep mistral-large and the framework.
        self::assertSame(
            ['language_model' => ['mistral-large'], 'framework' => ['openwebui']],
            $filters[0]->removeQuery,
        );
    }

    // Verifies that removing the last value of a facet drops the facet key entirely from removeQuery.
    public function testActiveFilterRemoveQueryDropsKeyWhenLastValueRemoved(): void
    {
        $criteria = new CatalogCriteria(
            languageModels: ['gpt-4o'],
            frameworks: ['openwebui'],
        );

        $filters = $criteria->activeFilters();

        // Removing the only LM value collapses the key entirely so the URL
        // emits `?framework[]=openwebui`, not `?language_model[]=&framework[]=…`.
        self::assertSame(['framework' => ['openwebui']], $filters[0]->removeQuery);
        self::assertArrayNotHasKey('language_model', $filters[0]->removeQuery);
    }

    // Ensures a request with no `?sort=` defaults to newest-first and omits sort from the query map.
    public function testFromRequestDefaultsSortToNewest(): void
    {
        $criteria = CatalogCriteria::fromRequest(Request::create('/search'), $this->lists);

        self::assertSame(CatalogSort::Newest, $criteria->sort);
        self::assertArrayNotHasKey('sort', $criteria->toQueryArray());
    }

    // Tests that `?sort=name` parses to the name-ascending case and round-trips through toQueryArray().
    public function testFromRequestReadsSortAndEmitsItWhenNonDefault(): void
    {
        $criteria = CatalogCriteria::fromRequest(
            Request::create('/search', 'GET', ['sort' => 'name']),
            $this->lists,
        );

        self::assertSame(CatalogSort::NameAsc, $criteria->sort);
        self::assertSame(['sort' => 'name'], $criteria->toQueryArray());
        self::assertTrue($criteria->isEmpty(), 'sort alone is not a filter');
    }

    // Ensures sort rides alongside the filters in toQueryArray so pagination links preserve the ordering.
    public function testToQueryArrayCarriesSortNextToFilters(): void
    {
        $criteria = new CatalogCriteria(q: 'borger', sort: CatalogSort::NameDesc);

        self::assertSame(['q' => 'borger', 'sort' => 'name_desc'], $criteria->toQueryArray());
    }

    // Ensures `?organization[]=` and `?data_sensitivity[]=` are read and round-trip through toQueryArray().
    public function testFromRequestReadsOrganizationAndDataSensitivityFacets(): void
    {
        $criteria = CatalogCriteria::fromRequest(
            Request::create('/search', 'GET', [
                'organization' => ['Aarhus Kommune', 'Odense Kommune'],
                'data_sensitivity' => ['confidential'],
            ]),
            $this->lists,
        );

        self::assertSame(['Aarhus Kommune', 'Odense Kommune'], $criteria->organizations);
        self::assertSame(['confidential'], $criteria->dataSensitivities);
        self::assertFalse($criteria->isEmpty(), 'either new facet alone makes the criteria non-empty');
        self::assertSame(
            [
                'organization' => ['Aarhus Kommune', 'Odense Kommune'],
                'data_sensitivity' => ['confidential'],
            ],
            $criteria->toQueryArray(),
        );
    }

    /**
     * The two facets were appended rather than interleaved, so the
     * pre-existing chips keep their positions — this pins that.
     */
    // Ensures the new facets' chips follow the tag chips, in declaration order.
    public function testActiveFiltersAppendsOrganizationThenDataSensitivityAfterTags(): void
    {
        $criteria = new CatalogCriteria(
            tags: ['jura'],
            organizations: ['Aarhus Kommune'],
            dataSensitivities: ['confidential'],
        );

        $types = array_map(static fn ($f) => $f->type, $criteria->activeFilters());

        self::assertSame(['tag', 'organization', 'data_sensitivity'], $types);
    }

    // Ensures a data-sensitivity chip carries the enum's translation key so the template can render a human label.
    public function testDataSensitivityChipLabelIsTheEnumTranslationKey(): void
    {
        $criteria = new CatalogCriteria(dataSensitivities: ['confidential']);

        $chip = $criteria->activeFilters()[0];

        self::assertSame('confidential', $chip->value, 'the machine value stays on the chip for removal maths');
        self::assertSame('assistant.data_sensitivity.confidential.label', $chip->label);
    }

    /**
     * A hand-edited query string can carry anything; the chip must still
     * render something rather than collapsing to an empty label.
     */
    // Ensures an unrecognised data-sensitivity value falls back to displaying itself.
    public function testUnknownDataSensitivityChipFallsBackToItsRawValue(): void
    {
        $criteria = new CatalogCriteria(dataSensitivities: ['not-a-case']);

        self::assertSame('not-a-case', $criteria->activeFilters()[0]->label);
    }

    // Ensures removing one kommune chip preserves the other selections on both new facets.
    public function testOrganizationChipRemoveQueryDropsOnlyTargetedValue(): void
    {
        $criteria = new CatalogCriteria(
            organizations: ['Aarhus Kommune', 'Odense Kommune'],
            dataSensitivities: ['confidential'],
        );

        $filters = $criteria->activeFilters();

        self::assertSame('Aarhus Kommune', $filters[0]->value);
        self::assertSame(
            [
                'organization' => ['Odense Kommune'],
                'data_sensitivity' => ['confidential'],
            ],
            $filters[0]->removeQuery,
        );
    }

    // Ensures removing the last data-sensitivity value drops the key entirely.
    public function testDataSensitivityChipRemoveQueryDropsKeyWhenLastValueRemoved(): void
    {
        $criteria = new CatalogCriteria(tags: ['jura'], dataSensitivities: ['confidential']);

        $filters = $criteria->activeFilters();

        self::assertSame('data_sensitivity', $filters[1]->type);
        self::assertSame(['tag' => ['jura']], $filters[1]->removeQuery);
    }
}
