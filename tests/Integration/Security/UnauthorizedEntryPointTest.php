<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end coverage of {@see \App\Security\UnauthorizedEntryPoint}.
 *
 * The default firewall entry point would either redirect to `/login`
 * or return an empty 401 response — Firefox in particular renders
 * its built-in "server sent back an error" page for the latter.
 * The custom entry point renders
 * `templates/security/unauthorized.html.twig` so the brand chrome
 * and a clear "log in" CTA stay visible.
 *
 * Companion to {@see AccessDeniedHandlerTest}, which covers the
 * authenticated-but-wrong-role (403) path.
 */
final class UnauthorizedEntryPointTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // Tests that an anonymous request to a gated route renders the templated 401 body, not a blank Symfony response.
    public function testRendersTemplatedUnauthorizedPageForAnonymousAccess(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(401);
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Log ind påkrævet', $body);
        self::assertStringContainsString('Du skal være logget ind', $body);
        // The page surfaces a login CTA and a "create account" link.
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href="/login"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href="/register"]')->count());
    }
}
