<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Catalog\CatalogCriteria;
use App\Catalog\CatalogSort;
use App\Entity\Assistant;
use App\Entity\Tag;
use App\Enum\DataSensitivity;
use App\Repository\AssistantRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssistantRepositoryTest extends KernelTestCase
{
    private AssistantRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(AssistantRepository::class);
    }

    // Tests that the repository is wired through the container and finds a known fixture row by title.
    public function testRepositoryIsResolvableAndFindsFixtureRow(): void
    {
        self::assertInstanceOf(AssistantRepository::class, $this->repository);

        $assistant = $this->repository->findOneBy(['title' => 'Borgerservice-vejviser']);

        self::assertInstanceOf(Assistant::class, $assistant);
        self::assertSame('Borgerservice-vejviser', $assistant->getTitle());
        self::assertSame(
            ['borgerservice', 'social', 'jura'],
            array_map(static fn (Tag $t) => $t->getName(), $assistant->getTags()->toArray()),
        );
    }

    // Tests that empty criteria returns every fixture row (no IN clauses applied).
    public function testFindPaginatedReturnsAllRowsForEmptyCriteria(): void
    {
        $paginator = $this->repository->findPaginated(new CatalogCriteria(), page: 1, perPage: 100);

        self::assertCount(21, $paginator, 'fixture baseline seeds 21 assistants');
        self::assertSame(21, iterator_count($paginator->getIterator()));
    }

    // Tests that the languageModels criterion narrows results to rows whose languageModel is in the selected list.
    public function testFindPaginatedFiltersByLanguageModel(): void
    {
        $criteria = new CatalogCriteria(languageModels: ['gpt-4o']);

        $paginator = $this->repository->findPaginated($criteria, page: 1, perPage: 100);

        $models = [];
        foreach ($paginator as $assistant) {
            self::assertInstanceOf(Assistant::class, $assistant);
            $models[] = $assistant->getLanguageModel();
        }
        self::assertNotEmpty($models, 'gpt-4o fixture rows must be reachable');
        self::assertSame(['gpt-4o'], array_values(array_unique($models)));
        self::assertCount(\count($models), $paginator);
    }

    // Tests that the frameworks criterion narrows results by framework value via the IN clause.
    public function testFindPaginatedFiltersByFramework(): void
    {
        $criteria = new CatalogCriteria(frameworks: ['openwebui']);

        $paginator = $this->repository->findPaginated($criteria, page: 1, perPage: 100);

        $frameworks = [];
        foreach ($paginator as $assistant) {
            self::assertInstanceOf(Assistant::class, $assistant);
            $frameworks[] = $assistant->getFramework();
        }
        // Every fixture row uses openwebui, so the IN clause must return all 21.
        self::assertCount(21, $paginator);
        self::assertSame(['openwebui'], array_values(array_unique($frameworks)));
    }

    // Ensures pagination yields disjoint pages in id-ASC order.
    public function testFindPaginatedAppliesOffsetForPaging(): void
    {
        $perPage = 10;

        // Oldest-first sorts by createdAt ASC with an id-ASC tiebreaker, so
        // (since fixtures share a creation instant) the page order is id-ASC.
        $criteria = new CatalogCriteria(sort: CatalogSort::Oldest);
        $firstPage = $this->repository->findPaginated($criteria, page: 1, perPage: $perPage);
        $secondPage = $this->repository->findPaginated($criteria, page: 2, perPage: $perPage);

        $firstIds = array_map(static fn (Assistant $a) => $a->getId(), iterator_to_array($firstPage->getIterator()));
        $secondIds = array_map(static fn (Assistant $a) => $a->getId(), iterator_to_array($secondPage->getIterator()));

        self::assertCount(10, $firstIds);
        self::assertCount(10, $secondIds);
        self::assertSame([], array_intersect($firstIds, $secondIds), 'pages must not overlap');
        self::assertGreaterThan(max($firstIds), min($secondIds), 'page 2 starts after page 1 by id-ASC order');
    }

    // Ensures persistedLanguageModels() returns every distinct non-empty stored id, ordered A→Z.
    public function testPersistedLanguageModelsListsDistinctStoredValues(): void
    {
        self::assertSame(
            ['gpt-4o', 'gpt-4o-mini', 'llama-3.1', 'llama-3.2', 'mistral', 'o3-mini'],
            $this->repository->persistedLanguageModels(),
        );
    }

    // Verifies the facet-count helpers reflect the fixture baseline (six canonical LM buckets summing to 21, single openwebui bucket).
    public function testFacetCountsReflectFixtureBaseline(): void
    {
        $languageModels = $this->repository->languageModelFacetCounts();
        $frameworks = $this->repository->frameworkFacetCounts();

        $languageModelKeys = array_keys($languageModels);
        sort($languageModelKeys);
        self::assertSame(
            ['gpt-4o', 'gpt-4o-mini', 'llama-3.1', 'llama-3.2', 'mistral', 'o3-mini'],
            $languageModelKeys,
        );
        self::assertSame(21, array_sum($languageModels), 'facet counts sum to total fixture rows');

        self::assertSame(['openwebui' => 21], $frameworks);
    }

    // Tests that a `q` query narrows to rows whose title contains the term (case-insensitive).
    public function testFindPaginatedFiltersByQueryOnTitle(): void
    {
        $criteria = new CatalogCriteria(q: 'JOURNALISERINGSASSISTENT');

        $paginator = $this->repository->findPaginated($criteria, page: 1, perPage: 100);

        $titles = array_map(static fn (Assistant $a) => $a->getTitle(), iterator_to_array($paginator->getIterator()));
        self::assertSame(['Journaliseringsassistent'], $titles, 'query matches the title case-insensitively');
    }

    // Tests that a `q` query also matches the description, reaching every row that mentions the term.
    public function testFindPaginatedFiltersByQueryOnDescription(): void
    {
        // "KPI-rapporter" appears only in the Statistikfortolker description,
        // generated twice across the fifteen rotated entries.
        $criteria = new CatalogCriteria(q: 'kpi');

        $paginator = $this->repository->findPaginated($criteria, page: 1, perPage: 100);

        self::assertCount(2, $paginator, 'both KPI-mentioning rows are reached via the description');
        foreach ($paginator as $assistant) {
            self::assertStringContainsStringIgnoringCase('kpi', $assistant->getDescription());
        }
    }

    // Tests that the tags criterion keeps rows carrying at least one of the named tags (OR-within), without duplicate rows.
    public function testFindPaginatedFiltersByTags(): void
    {
        $criteria = new CatalogCriteria(tags: ['jura']);

        $paginator = $this->repository->findPaginated($criteria, page: 1, perPage: 100);

        // 'jura' is on the Borgerservice-vejviser detailed row plus the two
        // Forvaltningsret-vejviser generated rows.
        self::assertCount(3, $paginator);
        foreach ($paginator as $assistant) {
            self::assertContains(
                'jura',
                array_map(static fn (Tag $t) => $t->getName(), $assistant->getTags()->toArray()),
            );
        }
    }

    // Ensures multiple tags OR within the facet — the result is the union, deduplicated to one row per assistant.
    public function testFindPaginatedTagsOrWithinFacet(): void
    {
        $criteria = new CatalogCriteria(tags: ['jura', 'arkiv']);

        $paginator = $this->repository->findPaginated($criteria, page: 1, perPage: 100);

        // jura (3 rows) ∪ arkiv (1 distinct row) = 4, no duplicates from the join.
        self::assertCount(4, $paginator);
        $ids = array_map(static fn (Assistant $a) => (string) $a->getId(), iterator_to_array($paginator->getIterator()));
        self::assertSame($ids, array_values(array_unique($ids)), 'no assistant appears twice');
    }

    // Verifies tagFacetCounts() reflects the fixture baseline: 24 distinct tags summing to 45, ordered by count DESC.
    public function testTagFacetCountsReflectFixtureBaseline(): void
    {
        $tags = $this->repository->tagFacetCounts();

        self::assertCount(24, $tags, 'fixture baseline seeds 24 distinct tags');
        self::assertSame(45, array_sum($tags), 'tag counts sum to the number of assistant-tag links');
        self::assertSame(3, $tags['jura'], 'jura spans one detailed and two generated rows');
        self::assertSame(1, $tags['social']);

        $counts = array_values($tags);
        $sorted = $counts;
        rsort($sorted);
        self::assertSame($sorted, $counts, 'buckets are ordered by count DESC');
    }

    // Ensures the kommune facet narrows to assistants shared by the named organisation.
    public function testFindPaginatedFiltersByOrganization(): void
    {
        $criteria = new CatalogCriteria(organizations: ['Aarhus Kommune']);

        $paginator = $this->repository->findPaginated($criteria, page: 1, perPage: 100);

        self::assertCount(3, $paginator);
        foreach ($paginator as $assistant) {
            self::assertNotNull($assistant->getOrganization());
            self::assertSame('Aarhus Kommune', $assistant->getOrganization()->getName());
        }
    }

    // Ensures multiple kommuner OR within the facet, one row per assistant.
    public function testFindPaginatedOrganizationsOrWithinFacet(): void
    {
        $criteria = new CatalogCriteria(organizations: ['Aarhus Kommune', 'Odense Kommune']);

        $paginator = $this->repository->findPaginated($criteria, page: 1, perPage: 100);

        self::assertCount(6, $paginator, 'three rows per kommune, unioned');
        $ids = array_map(static fn (Assistant $a) => (string) $a->getId(), iterator_to_array($paginator->getIterator()));
        self::assertSame($ids, array_values(array_unique($ids)), 'no assistant appears twice');
    }

    // Ensures the datafølsomhed facet narrows to assistants carrying that classification.
    public function testFindPaginatedFiltersByDataSensitivity(): void
    {
        $criteria = new CatalogCriteria(dataSensitivities: [DataSensitivity::Confidential->value]);

        $paginator = $this->repository->findPaginated($criteria, page: 1, perPage: 100);

        self::assertCount(7, $paginator);
        foreach ($paginator as $assistant) {
            self::assertSame(DataSensitivity::Confidential, $assistant->getDataSensitivity());
        }
    }

    /**
     * Assistants with no organisation are dropped by the inner join, so
     * the bucket total is deliberately below the catalogue size — the
     * facet offers kommuner to pick, not a census of every row.
     */
    // Verifies organizationFacetCounts() reflects the fixture baseline and excludes unattached rows.
    public function testOrganizationFacetCountsReflectFixtureBaseline(): void
    {
        $organizations = $this->repository->organizationFacetCounts();

        self::assertCount(3, $organizations, 'three kommuner carry at least one assistant');
        self::assertSame(9, array_sum($organizations));
        self::assertSame(3, $organizations['Aarhus Kommune']);
        self::assertLessThan(
            $this->repository->count([]),
            array_sum($organizations),
            'assistants without an organisation contribute to no bucket',
        );
    }

    // Verifies dataSensitivityFacetCounts() keys on the enum backing value and orders by count DESC.
    public function testDataSensitivityFacetCountsReflectFixtureBaseline(): void
    {
        $sensitivities = $this->repository->dataSensitivityFacetCounts();

        self::assertSame(
            [DataSensitivity::OrdinaryPersonal->value, DataSensitivity::Confidential->value, DataSensitivity::SensitivePersonal->value],
            array_keys($sensitivities),
            'keys are enum backing values, ordered by count DESC',
        );
        self::assertSame($this->repository->count([]), array_sum($sensitivities), 'every fixture row is classified');
    }

    // Tests that name-ascending orders by title A→Å, and name-descending is its exact reverse (titles are unique).
    public function testFindPaginatedSortsByName(): void
    {
        $asc = $this->titlesOf(new CatalogCriteria(sort: CatalogSort::NameAsc));
        $desc = $this->titlesOf(new CatalogCriteria(sort: CatalogSort::NameDesc));

        self::assertStringStartsWith('Borgerhenvendelse-svarudkast', $asc[0], 'A→Å lists the lowest title first');
        self::assertSame('Uden kategorier', $asc[array_key_last($asc)], 'A→Å lists the highest title last');
        self::assertSame(array_reverse($asc), $desc, 'name-descending is the exact reverse of name-ascending');
    }

    // Ensures the default (newest-first) ordering is the exact reverse of oldest-first across the fixture baseline.
    public function testFindPaginatedDefaultsToNewestFirst(): void
    {
        $newest = $this->idsOf(new CatalogCriteria());
        $oldest = $this->idsOf(new CatalogCriteria(sort: CatalogSort::Oldest));

        self::assertSame(CatalogSort::Newest, (new CatalogCriteria())->sort, 'empty criteria defaults to newest');
        self::assertCount(21, $newest);
        self::assertSame(array_reverse($oldest), $newest, 'newest-first reverses oldest-first');
    }

    // Verifies recently-updated falls back to the same id-DESC order as newest, since fixtures share an update instant.
    public function testFindPaginatedSortsByRecentlyUpdated(): void
    {
        $recentlyUpdated = $this->idsOf(new CatalogCriteria(sort: CatalogSort::RecentlyUpdated));
        $newest = $this->idsOf(new CatalogCriteria(sort: CatalogSort::Newest));

        self::assertSame($newest, $recentlyUpdated);
    }

    /**
     * Collect the titles of an unpaginated catalogue query in result order.
     *
     * @param CatalogCriteria $criteria the filter/sort selection to run
     *
     * @return list<string> assistant titles in the order the repository returned them
     */
    private function titlesOf(CatalogCriteria $criteria): array
    {
        $paginator = $this->repository->findPaginated($criteria, page: 1, perPage: 100);

        return array_map(static fn (Assistant $a) => $a->getTitle(), iterator_to_array($paginator->getIterator()));
    }

    /**
     * Collect the ids of an unpaginated catalogue query in result order.
     *
     * @param CatalogCriteria $criteria the filter/sort selection to run
     *
     * @return list<string> assistant ids (stringified ULIDs) in result order
     */
    private function idsOf(CatalogCriteria $criteria): array
    {
        $paginator = $this->repository->findPaginated($criteria, page: 1, perPage: 100);

        return array_map(static fn (Assistant $a) => (string) $a->getId(), iterator_to_array($paginator->getIterator()));
    }
}
