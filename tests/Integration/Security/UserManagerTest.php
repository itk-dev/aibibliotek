<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Security\UserManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests for {@see UserManager}.
 *
 * Relies on the baseline `UserFixtures` (alice + bob with password
 * `password`) loaded by `tests/bootstrap_integration.php`. Tests that
 * exercise the "create a brand-new user" path use a non-fixture email
 * (`charlie@example.test`) to avoid colliding with the baseline.
 */
final class UserManagerTest extends KernelTestCase
{
    private UserManager $userManager;
    private UserRepository $userRepository;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->userManager = $container->get(UserManager::class);
        $this->userRepository = $container->get(UserRepository::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
    }

    // Tests the happy path: createUser persists a user with hashed password, name, default Pending status (safe default), and ROLE_USER.
    public function testCreatesAndPersistsUserWithHashedPassword(): void
    {
        $user = $this->userManager->createUser('charlie@example.test', 'Charlie', 'secret');

        self::assertNotNull($user->getId());
        self::assertSame('charlie@example.test', $user->getEmail());
        self::assertSame('Charlie', $user->getName());
        self::assertSame(UserStatus::Pending, $user->getStatus());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertNotSame('secret', $user->getPassword(), 'Password must be hashed.');
        self::assertTrue(
            $this->passwordHasher->isPasswordValid($user, 'secret'),
            'Hashed password must verify against the original plain text.',
        );
        self::assertSame($user->getId(), $this->userRepository->findOneBy(['email' => 'charlie@example.test'])?->getId());
    }

    // Verifies that extra roles passed to createUser() are stored alongside the implicit ROLE_USER.
    public function testCreateUserStoresExtraRoles(): void
    {
        $user = $this->userManager->createUser('admin@example.test', 'Admin', 'secret', ['ROLE_ADMIN']);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    // Ensures createUser() throws DomainException when the email matches an existing user.
    public function testCreateUserRejectsDuplicateEmail(): void
    {
        // alice@example.test is loaded by UserFixtures in the bootstrap.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('alice@example.test');

        $this->userManager->createUser('alice@example.test', 'Alice', 'other');
    }

    // Ensures createUser() throws InvalidArgumentException when the password is empty.
    public function testCreateUserRejectsEmptyPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must not be empty.');

        $this->userManager->createUser('charlie@example.test', 'Charlie', '');
    }

    // Verifies omitting the password entirely (null) mints a random secret and persists a usable hash — enables fixtures that don't care about the credential.
    public function testCreateUserGeneratesRandomPasswordWhenNoneGiven(): void
    {
        $first = $this->userManager->createUser('nopass-one@example.test', 'NoPass One');
        $second = $this->userManager->createUser('nopass-two@example.test', 'NoPass Two');

        // Each generated password is opaque, but both must be non-empty and
        // must differ across calls — otherwise the "random" mint isn't random.
        self::assertNotEmpty($first->getPassword());
        self::assertNotEmpty($second->getPassword());
        self::assertNotSame($first->getPassword(), $second->getPassword());
    }

    // Tests that changePassword() replaces the stored hash and the new password verifies (while the old one no longer does).
    public function testChangePasswordReplacesTheHash(): void
    {
        $alice = $this->userRepository->findOneBy(['email' => 'alice@example.test']);
        self::assertNotNull($alice);
        $oldHash = $alice->getPassword();

        $updated = $this->userManager->changePassword('alice@example.test', 'new');

        self::assertSame($alice->getId(), $updated->getId());
        self::assertNotSame($oldHash, $updated->getPassword());
        self::assertTrue($this->passwordHasher->isPasswordValid($updated, 'new'));
        self::assertFalse($this->passwordHasher->isPasswordValid($updated, 'password'));
    }

    // Ensures changePassword() throws DomainException when no user matches the given email.
    public function testChangePasswordFailsWhenUserMissing(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nobody@example.test');

        $this->userManager->changePassword('nobody@example.test', 'whatever');
    }

    // Ensures changePassword() throws InvalidArgumentException when the new password is empty.
    public function testChangePasswordRejectsEmptyPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must not be empty.');

        $this->userManager->changePassword('alice@example.test', '');
    }

    // Tests that updateUser() rewrites every provided field and leaves the rest as-is.
    public function testUpdateUserAppliesEveryProvidedField(): void
    {
        $alice = $this->userRepository->findOneBy(['email' => 'alice@example.test']);
        self::assertNotNull($alice);
        $originalPassword = $alice->getPassword();

        $updated = $this->userManager->updateUser(
            'alice@example.test',
            name: 'Alice A.',
            roles: ['ROLE_ADMIN'],
            status: UserStatus::Blocked,
        );

        self::assertSame($alice->getId(), $updated->getId());
        self::assertSame('Alice A.', $updated->getName());
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $updated->getRoles());
        self::assertSame(UserStatus::Blocked, $updated->getStatus());
        self::assertSame($originalPassword, $updated->getPassword(), 'Password must not be touched by updateUser().');
    }

    // Verifies that omitting every option is a no-op success and leaves the user untouched.
    public function testUpdateUserWithoutAnyFieldIsANoOp(): void
    {
        $alice = $this->userRepository->findOneBy(['email' => 'alice@example.test']);
        self::assertNotNull($alice);
        $beforeName = $alice->getName();
        $beforeRoles = $alice->getRoles();
        $beforeStatus = $alice->getStatus();

        $updated = $this->userManager->updateUser('alice@example.test');

        self::assertSame($beforeName, $updated->getName());
        self::assertSame($beforeRoles, $updated->getRoles());
        self::assertSame($beforeStatus, $updated->getStatus());
    }

    // Ensures passing an empty roles array clears every custom role (ROLE_USER floor stays via getRoles()).
    public function testUpdateUserClearsRolesWhenEmptyArrayPassed(): void
    {
        // Set a non-default role first so the clear has something to clear.
        $this->userManager->updateUser('alice@example.test', roles: ['ROLE_DOMAIN_MANAGER']);

        $updated = $this->userManager->updateUser('alice@example.test', roles: []);

        self::assertSame(['ROLE_USER'], $updated->getRoles());
    }

    // Ensures updateUser() rejects an unknown role with InvalidArgumentException and persists nothing.
    public function testUpdateUserRejectsUnknownRole(): void
    {
        $alice = $this->userRepository->findOneBy(['email' => 'alice@example.test']);
        self::assertNotNull($alice);
        $beforeName = $alice->getName();

        try {
            $this->userManager->updateUser(
                'alice@example.test',
                name: 'Should not stick',
                roles: ['ROLE_BOGUS'],
            );
            self::fail('Expected InvalidArgumentException for unknown role.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('ROLE_BOGUS', $e->getMessage());
        }

        $reloaded = $this->userRepository->findOneBy(['email' => 'alice@example.test']);
        self::assertNotNull($reloaded);
        self::assertSame($beforeName, $reloaded->getName(), 'Validation must run before any mutation is persisted.');
    }

    // Ensures updateUser() throws DomainException when the email matches no user.
    public function testUpdateUserFailsWhenUserMissing(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nobody@example.test');

        $this->userManager->updateUser('nobody@example.test', name: 'x');
    }
}
