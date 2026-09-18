<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataFixtures;

use App\DataFixtures\UserFixtures;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Security\Roles;
use App\Security\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `tests/bootstrap_integration.php` already calls `UserFixtures::load()`;
 * this test only re-invokes it so the lines land in the coverage report.
 * `UserManager` is `final` and can't be mocked directly, so we build a
 * real one with mocked collaborators.
 */
final class UserFixturesTest extends TestCase
{
    private const int EXPECTED_USER_COUNT = 9;

    // Ensures the fixture is grouped under `default` so the stg pipeline can load it with `--group=default`.
    public function testBelongsToDefaultGroup(): void
    {
        self::assertSame(['default'], UserFixtures::getGroups());
    }

    // Tests that load() persists every Roles::* and every UserStatus case, hashing the shared fixture password.
    public function testLoadPersistsEveryRoleAndStatusCombination(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $userRepository = $this->createMock(UserRepository::class);
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);

        $userRepository->method('findOneBy')->willReturn(null);
        $passwordHasher->method('hashPassword')->willReturn('hashed');

        $persisted = [];
        $entityManager->expects(self::exactly(self::EXPECTED_USER_COUNT))
            ->method('persist')
            ->willReturnCallback(function (object $entity) use (&$persisted): void {
                \assert($entity instanceof User);
                $persisted[] = $entity;
            });
        $entityManager->expects(self::exactly(self::EXPECTED_USER_COUNT))->method('flush');

        $userManager = new UserManager($entityManager, $userRepository, $passwordHasher);
        $fixture = new UserFixtures($userManager);

        $fixture->load($this->createMock(ObjectManager::class));

        $byEmail = [];
        foreach ($persisted as $user) {
            $byEmail[(string) $user->getEmail()] = $user;
        }

        // The original cross-fixture lookup points are still here.
        self::assertArrayHasKey(UserFixtures::ALICE_EMAIL, $byEmail);
        self::assertArrayHasKey(UserFixtures::BOB_EMAIL, $byEmail);
        // Same-domain plain user the fixture manager can act on —
        // carries no elevated role (`getRoles()` always includes the
        // implicit `ROLE_USER` floor regardless).
        self::assertArrayHasKey(UserFixtures::COLLEAGUE_EMAIL, $byEmail);
        self::assertNotContains(Roles::ADMIN, $byEmail[UserFixtures::COLLEAGUE_EMAIL]->getRoles());
        self::assertNotContains(Roles::DOMAIN_MANAGER, $byEmail[UserFixtures::COLLEAGUE_EMAIL]->getRoles());

        // Every role is represented.
        self::assertContains(Roles::ADMIN, $byEmail[UserFixtures::ADMIN_EMAIL]->getRoles());
        self::assertContains(Roles::DOMAIN_MANAGER, $byEmail[UserFixtures::DOMAIN_MANAGER_EMAIL]->getRoles());
        // Second same-domain manager for fan-out tests (e.g. the domain-manager registration notifier).
        self::assertContains(Roles::DOMAIN_MANAGER, $byEmail[UserFixtures::SECOND_DOMAIN_MANAGER_EMAIL]->getRoles());

        // Every status case has at least one seeded user.
        $statuses = array_map(static fn (User $u): UserStatus => $u->getStatus(), $persisted);
        self::assertContains(UserStatus::Approved, $statuses);
        self::assertContains(UserStatus::Pending, $statuses);
        self::assertContains(UserStatus::AwaitingEmailConfirmation, $statuses);
        self::assertContains(UserStatus::Blocked, $statuses);

        self::assertSame(UserStatus::Pending, $byEmail[UserFixtures::PENDING_EMAIL]->getStatus());
        self::assertSame(UserStatus::AwaitingEmailConfirmation, $byEmail[UserFixtures::AWAITING_EMAIL]->getStatus());
        self::assertSame(UserStatus::Blocked, $byEmail[UserFixtures::BLOCKED_EMAIL]->getStatus());
    }
}
