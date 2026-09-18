<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Repository\AssistantRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end coverage of the assistant catalogue page.
 *
 * Drives `GET /search` through the real controller + repository +
 * Twig render path. Uses the baseline catalogue loaded by
 * `tests/bootstrap_integration.php` (see `AssistantFixtures`,
 * 21 entries). DAMA rolls back per-test mutations — none of these
 * tests mutate the baseline so isolation is incidental.
 */
final class AssistantCatalogControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The catalogue is gated, so log in a baseline fixture user
        // before each test so the page-render assertions below see
        // actual content rather than an unauthorised response.
        $alice = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'alice@example.test']);
        \assert(null !== $alice, 'UserFixtures must seed alice@example.test.');
        $this->client->loginUser($alice);
    }

    // Tests that GET /search renders the page heading and at least one fixture-backed assistant card.
    public function testIndexRendersResultsHeadingAndCards(): void
    {
        $crawler = $this->client->request('GET', '/search');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Find en assistent');
        self::assertGreaterThanOrEqual(
            1,
            $crawler->filter('.assistant-card')->count(),
            'catalogue must surface at least one fixture entry on the first page',
        );
    }

    // Tests that ?language_model[]=… narrows the card list to the matching facet count and renders the active-filter chip.
    public function testLanguageModelFilterNarrowsResults(): void
    {
        $expected = self::getContainer()->get(AssistantRepository::class)->languageModelFacetCounts()['gpt-4o'] ?? 0;
        self::assertGreaterThan(0, $expected, 'fixture baseline must include gpt-4o rows');

        $crawler = $this->client->request('GET', '/search?language_model%5B%5D=gpt-4o');

        self::assertResponseIsSuccessful();
        self::assertSame(
            $expected,
            $crawler->filter('.assistant-card')->count(),
            'card count must match the gpt-4o fixture facet count',
        );
        self::assertSelectorTextContains('[aria-label="Aktive filtre"]', 'gpt-4o');
    }

    // Ensures a filter value that matches nothing renders the empty-state copy and zero card links.
    public function testEmptyStateRendersWhenNoResults(): void
    {
        $crawler = $this->client->request('GET', '/search?language_model%5B%5D=does-not-exist');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ingen assistenter matcher');
        self::assertCount(0, $crawler->filter('.assistant-card'));
    }

    // Verifies pagination renders a current-page badge and a working next link when results span multiple pages.
    public function testPaginationShowsForMultiplePages(): void
    {
        // 21 fixture rows / PER_PAGE 12 → 2 pages.
        $crawler = $this->client->request('GET', '/search');

        self::assertResponseIsSuccessful();

        $next = $crawler->filter('a[rel="next"]');
        self::assertCount(1, $next, 'page 1 must offer a "next" link to page 2');
        self::assertStringContainsString('page=2', (string) $next->attr('href'));

        // Scope to <main> so the nav's active Catalog link (which now
        // also carries aria-current="page") doesn't inflate the match.
        $current = $crawler->filter('main [aria-current="page"]');
        self::assertSame('1', trim($current->text()), 'page 1 badge marks the current page');

        $page2Link = $crawler->filter('a[aria-label="Side 2"]');
        self::assertCount(1, $page2Link, 'numbered page-2 link is present');
    }

    // Ensures an active-filter chip's href drops only its targeted value while preserving the other filters.
    public function testChipRemoveLinkDropsTheFilteredValue(): void
    {
        $crawler = $this->client->request(
            'GET',
            '/search?language_model%5B%5D=gpt-4o&framework%5B%5D=openwebui',
        );

        self::assertResponseIsSuccessful();

        $chip = $crawler->filter('[aria-label="Aktive filtre"] a')->reduce(static function ($node) {
            return str_contains((string) $node->attr('aria-label'), 'gpt-4o');
        });
        self::assertCount(1, $chip, 'a removal chip for gpt-4o must be rendered');

        $params = [];
        parse_str(parse_url((string) $chip->attr('href'), \PHP_URL_QUERY) ?? '', $params);

        self::assertArrayNotHasKey('language_model', $params, 'chip removes the language_model filter');
        self::assertSame(['openwebui'], $params['framework'] ?? null, 'chip preserves the framework filter');
    }

    // Tests that ?q=… narrows the cards to title/description matches and renders the quoted search chip.
    public function testSearchQueryNarrowsResultsAndRendersChip(): void
    {
        $crawler = $this->client->request('GET', '/search?q=journaliseringsassistent');

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('.assistant-card'),
            'the query matches exactly one fixture title',
        );
        self::assertSelectorTextContains('[aria-label="Aktive filtre"]', '"journaliseringsassistent"');
    }

    // Tests that ?tag[]=… narrows the card list to the matching tag-facet count and renders the tag chip.
    public function testTagFilterNarrowsResults(): void
    {
        $expected = self::getContainer()->get(AssistantRepository::class)->tagFacetCounts()['jura'] ?? 0;
        self::assertGreaterThan(0, $expected, 'fixture baseline must include jura-tagged rows');

        $crawler = $this->client->request('GET', '/search?tag%5B%5D=jura');

        self::assertResponseIsSuccessful();
        self::assertSame(
            $expected,
            $crawler->filter('.assistant-card')->count(),
            'card count must match the jura fixture tag-facet count',
        );
        self::assertSelectorTextContains('[aria-label="Aktive filtre"]', 'jura');
    }

    // Ensures a search query and a tag filter combine (AND-across) to the intersection of both.
    public function testSearchQueryAndTagCombine(): void
    {
        // 'jura' tags three rows; only Borgerservice-vejviser also mentions
        // "borgerservice", so the intersection is a single card.
        $crawler = $this->client->request('GET', '/search?q=borgerservice&tag%5B%5D=jura');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.assistant-card'));
        self::assertSelectorTextContains('.assistant-card', 'Borgerservice-vejviser');
    }

    // Ensures a tag chip's href drops only its tag while preserving the search query.
    public function testTagChipRemoveLinkPreservesQuery(): void
    {
        $crawler = $this->client->request('GET', '/search?q=borgerservice&tag%5B%5D=jura');

        self::assertResponseIsSuccessful();

        $chip = $crawler->filter('[aria-label="Aktive filtre"] a')->reduce(static function ($node) {
            return str_contains((string) $node->attr('aria-label'), 'jura');
        });
        self::assertCount(1, $chip, 'a removal chip for the jura tag must be rendered');

        $params = [];
        parse_str(parse_url((string) $chip->attr('href'), \PHP_URL_QUERY) ?? '', $params);

        self::assertArrayNotHasKey('tag', $params, 'chip removes the tag filter');
        self::assertSame('borgerservice', $params['q'] ?? null, 'chip preserves the search query');
    }

    // Tests that the sort control renders with the newest-first default option preselected.
    public function testSortControlRendersWithDefaultSelected(): void
    {
        $crawler = $this->client->request('GET', '/search');

        self::assertResponseIsSuccessful();
        $selected = $crawler->filter('#catalog-sort option[selected]');
        self::assertCount(1, $selected, 'exactly one sort option is preselected');
        self::assertSame('newest', $selected->attr('value'), 'newest-first is the default selection');
    }

    // Ensures ?sort=name reorders the cards so the alphabetically-first title leads the listing.
    public function testSortByNameReordersResults(): void
    {
        $crawler = $this->client->request('GET', '/search?sort=name');

        self::assertResponseIsSuccessful();
        self::assertSame('name', $crawler->filter('#catalog-sort option[selected]')->attr('value'));
        self::assertStringContainsString(
            'Borgerhenvendelse-svarudkast',
            $crawler->filter('.assistant-card')->first()->text(),
            'name-ascending puts the lowest title first',
        );
    }

    // Ensures the sort form carries the active facet as a hidden input so changing the order preserves the filter.
    public function testSortFormPreservesActiveFilters(): void
    {
        $crawler = $this->client->request('GET', '/search?language_model%5B%5D=gpt-4o');

        self::assertResponseIsSuccessful();
        $hidden = $crawler->filter('aside[aria-label="Sortering"] input[type="hidden"][name="language_model[]"]');
        self::assertCount(1, $hidden, 'the sort form mirrors the active facet as a hidden input');
        self::assertSame('gpt-4o', $hidden->attr('value'));
    }

    // Ensures the filter form carries the active sort as a hidden input so toggling a facet preserves the ordering.
    public function testFilterFormCarriesActiveSort(): void
    {
        $crawler = $this->client->request('GET', '/search?sort=name');

        self::assertResponseIsSuccessful();
        $hidden = $crawler->filter('aside[aria-label="Filtre"] input[type="hidden"][name="sort"]');
        self::assertCount(1, $hidden, 'the filter form mirrors the active sort as a hidden input');
        self::assertSame('name', $hidden->attr('value'));
    }

    // Ensures the default ordering is not echoed as a hidden sort input, keeping the filter form URL clean.
    public function testFilterFormOmitsDefaultSort(): void
    {
        $crawler = $this->client->request('GET', '/search');

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('aside[aria-label="Filtre"] input[type="hidden"][name="sort"]'),
            'the default sort is implicit and must not be emitted as a hidden input',
        );
    }

    // Ensures the search box renders in the results column, not inside the filter rail.
    public function testSearchBoxRendersInResultsColumn(): void
    {
        $crawler = $this->client->request('GET', '/search');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('section #catalog-search'), 'the search box sits in the results column');
        self::assertCount(
            0,
            $crawler->filter('aside[aria-label="Filtre"] #catalog-search'),
            'the search box no longer lives in the filter rail',
        );
    }

    // Ensures the filter form carries the active search query so toggling a facet preserves it.
    public function testFilterFormCarriesSearchQuery(): void
    {
        $crawler = $this->client->request('GET', '/search?q=borgerservice');

        self::assertResponseIsSuccessful();
        $hidden = $crawler->filter('aside[aria-label="Filtre"] input[type="hidden"][name="q"]');
        self::assertCount(1, $hidden, 'the filter form mirrors the active search query as a hidden input');
        self::assertSame('borgerservice', $hidden->attr('value'));
    }

    // Ensures the "Aktive filtre" sidebar box shows its empty state when no filter is applied.
    public function testActiveFiltersBoxShowsEmptyStateWhenNoFilters(): void
    {
        $crawler = $this->client->request('GET', '/search');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('aside[aria-label="Aktive filtre"]', 'Ingen filtre aktive');
    }

    // Ensures the "Seneste søgninger" box shows its empty state before any search is run.
    public function testRecentSearchesBoxStartsEmpty(): void
    {
        $crawler = $this->client->request('GET', '/search');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('aside[aria-label="Seneste søgninger"]', 'Ingen søgninger endnu');
    }

    // Tests that a performed search is recorded and rendered as a re-runnable link in the recent-searches box.
    public function testRecentSearchesBoxListsThePerformedSearch(): void
    {
        $crawler = $this->client->request('GET', '/search?q=borgerservice');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('aside[aria-label="Seneste søgninger"] a[href*="q=borgerservice"]');
        self::assertCount(1, $link, 'the recent-searches box links back to the performed search');
        self::assertSame('borgerservice', trim($link->text()));
    }

    // Verifies the "Klar til download" box reports the current result count.
    public function testReadyForExportBoxReportsResultCount(): void
    {
        $total = self::getContainer()->get(AssistantRepository::class)->frameworkFacetCounts()['openwebui'] ?? 0;
        self::assertGreaterThan(0, $total, 'fixture baseline must seed assistants');

        $crawler = $this->client->request('GET', '/search');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(
            'aside[aria-label="Klar til download"]',
            sprintf('Alle %d resultater', $total),
        );
    }
}
