<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\DataFixtures\UserFixtures;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end coverage of the self-service profile edit page.
 *
 * The subject is always the authenticated user. The route
 * accepts no id / e-mail in path or payload, so the test only
 * verifies the round-trip for each seeded role plus the
 * unauthenticated redirect.
 */
final class ProfileControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // Verifies an anonymous request to /profile/edit gets a 401 from the project's default-deny entry point.
    public function testAnonymousAccessReturnsUnauthorized(): void
    {
        $this->client->request('GET', '/profile/edit');

        self::assertResponseStatusCodeSame(401);
    }

    // Verifies a plain authenticated user (alice) sees their own name pre-filled in the form.
    public function testFormPrefillsWithCurrentUsersName(): void
    {
        $this->loginAs(UserFixtures::ALICE_EMAIL);

        $crawler = $this->client->request('GET', '/profile/edit');

        self::assertResponseIsSuccessful();
        self::assertSame('Alice', $crawler->filter('input[name="profile[name]"]')->attr('value'));
    }

    // Tests the happy path: a plain user updates their own name and only their own row changes.
    public function testPlainUserCanUpdateOwnName(): void
    {
        $this->loginAs(UserFixtures::ALICE_EMAIL);
        $crawler = $this->client->request('GET', '/profile/edit');

        $form = $crawler->filter('form')->form([
            'profile[name]' => 'Alice Alpha',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/profile/edit');
        self::assertUserNameIs(UserFixtures::ALICE_EMAIL, 'Alice Alpha');
        self::assertUserNameIs(UserFixtures::BOB_EMAIL, 'Bob', 'Bob must not be touched by Alice editing her own profile.');
    }

    // Verifies the same flow works for a site admin — no admin-side path involved, no role escalation.
    public function testAdminCanUpdateOwnName(): void
    {
        $this->loginAs(UserFixtures::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', '/profile/edit');

        $form = $crawler->filter('form')->form([
            'profile[name]' => 'Admin Adminson',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/profile/edit');
        self::assertUserNameIs(UserFixtures::ADMIN_EMAIL, 'Admin Adminson');
    }

    // Verifies the same flow works for a domain manager.
    public function testDomainManagerCanUpdateOwnName(): void
    {
        $this->loginAs(UserFixtures::DOMAIN_MANAGER_EMAIL);
        $crawler = $this->client->request('GET', '/profile/edit');

        $form = $crawler->filter('form')->form([
            'profile[name]' => 'Manager Manchester',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/profile/edit');
        self::assertUserNameIs(UserFixtures::DOMAIN_MANAGER_EMAIL, 'Manager Manchester');
    }

    // Verifies submitting an empty name is rejected with 422 and the row is untouched.
    public function testBlankNameIsRejectedWith422(): void
    {
        $this->loginAs(UserFixtures::ALICE_EMAIL);
        $crawler = $this->client->request('GET', '/profile/edit');

        $form = $crawler->filter('form')->form([
            'profile[name]' => '',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertUserNameIs(UserFixtures::ALICE_EMAIL, 'Alice');
    }

    private function loginAs(string $email): void
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        \assert(null !== $user, \sprintf('Seeded user %s must exist.', $email));
        $this->client->loginUser($user);
    }

    private static function assertUserNameIs(string $email, string $expected, string $message = ''): void
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        \assert(null !== $user, \sprintf('Seeded user %s must exist.', $email));
        self::assertSame($expected, $user->getName(), $message);
    }
}
