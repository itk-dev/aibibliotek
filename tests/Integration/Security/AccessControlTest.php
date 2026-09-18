<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end coverage of the default-deny `access_control` rule on
 * the `main` firewall.
 *
 * Every route is gated behind `IS_AUTHENTICATED_FULLY` except a
 * short `PUBLIC_ACCESS` allow-list (login, logout, registration).
 * Anonymous requests to gated routes return 401.
 */
final class AccessControlTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gatedRouteProvider(): iterable
    {
        yield 'frontpage' => ['/'];
        yield 'catalogue search' => ['/search'];
        yield 'assistant detail' => ['/assistant/01ARZ3NDEKTSV4RRFFQ69G5FAV'];
        yield 'admin user list' => ['/admin/users'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicRouteProvider(): iterable
    {
        yield 'login form' => ['/login'];
        yield 'registration form' => ['/register'];
        yield 'registration pending page' => ['/register/pending'];
    }

    // Verifies that anonymous requests to gated routes return HTTP 401.
    #[\PHPUnit\Framework\Attributes\DataProvider('gatedRouteProvider')]
    public function testAnonymousAccessReturnsUnauthorized(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertResponseStatusCodeSame(401, $path.' must return 401 for anonymous visitors');
    }

    // Tests that every PUBLIC_ACCESS allow-list route renders for anonymous visitors.
    #[\PHPUnit\Framework\Attributes\DataProvider('publicRouteProvider')]
    public function testPublicRouteReachableAnonymously(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertResponseIsSuccessful($path.' must be reachable without authentication');
    }

    // Verifies that an authenticated user can reach a previously-gated route.
    public function testAuthenticatedUserCanReachGatedRoute(): void
    {
        $alice = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'alice@example.test']);
        \assert(null !== $alice);
        $this->client->loginUser($alice);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }
}
