<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\UserFixtures;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke coverage of the primary site header rendered from
 * `templates/base.html.twig`.
 *
 * Guards two invariants the design cleanup landed:
 *
 * - No `href="#"` placeholder links in the header — every entry
 *   navigates somewhere real.
 * - The user-menu opener appears only for signed-in visitors;
 *   anonymous requests reach the login page instead, so we drive
 *   these assertions from `/register` (a public route) rather
 *   than the gated frontpage.
 */
final class NavigationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // Ensures the primary nav renders exactly the expected real destinations for a signed-in user, no href="#" left.
    public function testSignedInNavRendersExpectedLinks(): void
    {
        $alice = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => UserFixtures::ALICE_EMAIL]);
        \assert(null !== $alice, 'UserFixtures must seed alice@example.test.');
        $this->client->loginUser($alice);

        $crawler = $this->client->request('GET', '/search');

        self::assertResponseIsSuccessful();
        $nav = $crawler->filter('nav[aria-label="Hovedmenu"]');
        self::assertCount(1, $nav);

        $links = $nav->filter('a[href]')->each(static fn ($node) => (string) $node->attr('href'));
        self::assertContains('/search', $links);
        self::assertContains('/assistant/new', $links);
        self::assertContains('/mine/assistenter', $links);
        self::assertNotContains('#', $links, 'no dead placeholder hrefs may remain in the primary nav');
    }

    // Verifies the signed-out nav omits the Mine assistenter link + the user menu opener.
    public function testAnonymousNavOmitsAuthedEntries(): void
    {
        $crawler = $this->client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        $nav = $crawler->filter('nav[aria-label="Hovedmenu"]');

        $links = $nav->filter('a[href]')->each(static fn ($node) => (string) $node->attr('href'));
        self::assertContains('/search', $links);
        self::assertContains('/assistant/new', $links);
        self::assertNotContains('/mine/assistenter', $links);
        self::assertCount(0, $nav->filter('[data-controller="user-menu"]'), 'no user-menu opener may render for anonymous visitors');
    }
}
