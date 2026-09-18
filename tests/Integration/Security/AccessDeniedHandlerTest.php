<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end coverage of {@see \App\Security\AccessDeniedHandler}.
 *
 * Symfony's default 403 path returns a blank response. The handler
 * wired on the `main` firewall renders the
 * `security/access_denied.html.twig` template instead so the
 * operator gets a branded page. This test drives the handler by
 * having alice (plain user) hit `/admin/users`, which her role
 * doesn't satisfy, and asserts both the status code and the
 * presence of the Danish copy in the rendered body.
 */
final class AccessDeniedHandlerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // Tests that an authenticated user without the required role sees the templated 403 page (not a blank Symfony response).
    public function testRendersTemplatedForbiddenPageForAuthenticatedUser(): void
    {
        $this->loginAs('alice@example.test');

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertResponseStatusCodeSame(403);
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Adgang nægtet', $body);
        self::assertStringContainsString('Du har ikke adgang', $body);
        // The page renders the public base layout, so the SiteHeader's
        // brand link adds a second anchor to `/`. Assert at least one
        // — proves the back-to-frontpage link is present.
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href="/"]')->count());
        self::assertStringContainsString('Til forsiden', $body);
    }

    private function loginAs(string $email): void
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        \assert(null !== $user, \sprintf('Seeded user %s must exist.', $email));
        $this->client->loginUser($user);
    }
}
