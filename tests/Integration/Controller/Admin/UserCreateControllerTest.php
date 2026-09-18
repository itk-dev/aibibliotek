<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\DataFixtures\UserFixtures;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end coverage of the admin create-user form at
 * `/admin/users/new`.
 *
 * The form is gated behind `ROLE_ADMIN` (override of the
 * controller-level `ROLE_DOMAIN_MANAGER`), submits a non-mapped
 * payload that the controller hands to
 * {@see UserManager::createUser()}, and redirects back to the
 * user list on success.
 */
final class UserCreateControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // Tests that an anonymous request to /admin/users/new returns 401.
    public function testAnonymousAccessReturnsUnauthorized(): void
    {
        $this->client->request('GET', '/admin/users/new');

        self::assertResponseStatusCodeSame(401);
    }

    // Tests that a domain manager without ROLE_ADMIN is 403'd — only site admins can create.
    public function testDomainManagerGets403(): void
    {
        $this->loginAsApproved(UserFixtures::DOMAIN_MANAGER_EMAIL);

        $this->client->request('GET', '/admin/users/new');

        self::assertResponseStatusCodeSame(403);
    }

    // Verifies an admin sees a fresh empty form with every expected field rendered.
    public function testAdminSeesEmptyForm(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/users/new');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="user_create[email]"]'));
        self::assertCount(1, $crawler->filter('input[name="user_create[name]"]'));
        self::assertCount(1, $crawler->filter('input[name="user_create[password]"]'));
        self::assertCount(1, $crawler->filter('select[name="user_create[status]"]'));
    }

    // Tests the happy path: a valid submission creates an approved user and redirects to the list.
    public function testValidSubmitCreatesUserAndRedirects(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/users/new');
        $form = $crawler->filter('form')->form([
            'user_create[email]' => 'charlie@example.test',
            'user_create[name]' => 'Charlie',
            'user_create[password]' => 'secret',
            'user_create[status]' => UserStatus::Approved->value,
            'user_create[roles]' => [Roles::DOMAIN_MANAGER],
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/users');

        $created = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'charlie@example.test']);
        self::assertNotNull($created);
        self::assertSame('Charlie', $created->getName());
        self::assertSame(UserStatus::Approved, $created->getStatus());
        self::assertContains(Roles::DOMAIN_MANAGER, $created->getRoles());

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($created, 'secret'), 'password must be hashed via UserManager');
    }

    // Ensures an empty email is rejected with 422 and nothing is persisted.
    public function testSubmitWithoutEmailIsRejected(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/users/new');
        $form = $crawler->filter('form')->form([
            'user_create[email]' => '',
            'user_create[name]' => 'No Email',
            'user_create[password]' => 'secret',
            'user_create[status]' => UserStatus::Approved->value,
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneBy(['name' => 'No Email']));
    }

    // Verifies a duplicate email re-renders with 422 and surfaces the DomainException message.
    public function testDuplicateEmailRendersWithDomainError(): void
    {
        $this->loginAsAdmin();
        // alice@example.test is in the baseline fixtures.

        $crawler = $this->client->request('GET', '/admin/users/new');
        $form = $crawler->filter('form')->form([
            'user_create[email]' => 'alice@example.test',
            'user_create[name]' => 'Duplicate',
            'user_create[password]' => 'secret',
            'user_create[status]' => UserStatus::Approved->value,
        ]);
        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('alice@example.test', $crawler->filter('[role="alert"]')->text());
    }

    // Ensures an invalid form CSRF token is rejected (the form widget includes the token automatically; this case crafts a bad POST).
    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->loginAsAdmin();

        $this->client->request('POST', '/admin/users/new', [
            'user_create' => [
                'email' => 'mallory@example.test',
                'name' => 'Mallory',
                'password' => 'secret',
                'status' => UserStatus::Approved->value,
                '_token' => 'nope',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'mallory@example.test']));
    }

    private function loginAsAdmin(): void
    {
        $this->loginAsApproved(UserFixtures::ADMIN_EMAIL);
    }

    private function loginAsApproved(string $email): void
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        \assert(null !== $user, 'Test user must be created before login.');
        $this->client->loginUser($user);
    }
}
