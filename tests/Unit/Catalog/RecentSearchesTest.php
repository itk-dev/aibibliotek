<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\RecentSearches;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class RecentSearchesTest extends TestCase
{
    private RecentSearches $recentSearches;

    protected function setUp(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        $this->recentSearches = new RecentSearches($stack);
    }

    // Tests that recorded queries come back most-recent-first.
    public function testRecordsQueriesMostRecentFirst(): void
    {
        $this->recentSearches->record('alfa');
        $this->recentSearches->record('beta');
        $this->recentSearches->record('gamma');

        self::assertSame(['gamma', 'beta', 'alfa'], $this->recentSearches->all());
    }

    // Ensures a blank or whitespace-only query is ignored.
    public function testIgnoresBlankQueries(): void
    {
        $this->recentSearches->record('');
        $this->recentSearches->record('   ');

        self::assertSame([], $this->recentSearches->all());
    }

    // Ensures re-searching a term (case-insensitively) moves it to the front without duplicating.
    public function testDeduplicatesCaseInsensitivelyAndMovesToFront(): void
    {
        $this->recentSearches->record('fisk');
        $this->recentSearches->record('hund');
        $this->recentSearches->record('FISK');

        self::assertSame(['FISK', 'hund'], $this->recentSearches->all());
    }

    // Verifies the history is capped, dropping the oldest entries past the limit.
    public function testCapsHistoryLength(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $term) {
            $this->recentSearches->record($term);
        }

        self::assertSame(['f', 'e', 'd', 'c', 'b'], $this->recentSearches->all());
    }

    // Ensures a non-array session value is treated as an empty history rather than erroring.
    public function testReadsNonArraySessionValueAsEmpty(): void
    {
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $session->set('catalog.recent_searches', 'corrupted');
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        self::assertSame([], (new RecentSearches($stack))->all());
    }
}
