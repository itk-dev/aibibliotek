<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\DataFixtures\UserFixtures;
use App\Repository\UserRepository;
use App\Security\LastAdminException;
use App\Security\Roles;
use App\Security\UserManager;
use App\Security\UserRoles;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end coverage of {@see UserRoles}, the role-transition
 * service that backs the inline role-picker on /admin/users.
 *
 * Runs against the integration suite's real Doctrine wiring so the
 * `UserManager::updateUser()` flush is exercised and the
 * last-admin guard is checked against an actual `countAdmins()`
 * query.
 */
final class UserRolesTest extends KernelTestCase
{
    private UserManager $userManager;
    private UserRoles $userRoles;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->userManager = $container->get(UserManager::class);
        $this->userRoles = $container->get(UserRoles::class);
        $this->userRepository = $container->get(UserRepository::class);
    }

    // Verifies promoteToManager replaces the role list with ROLE_DOMAIN_MANAGER.
    public function testPromoteToManagerSetsRoleDomainManager(): void
    {
        $user = $this->userRepository->findOneBy(['email' => UserFixtures::ALICE_EMAIL]);
        self::assertNotNull($user);

        $this->userRoles->promoteToManager($user);

        $reloaded = $this->userRepository->find($user->getId());
        self::assertNotNull($reloaded);
        self::assertContains(Roles::DOMAIN_MANAGER, $reloaded->getRoles());
        self::assertNotContains(Roles::ADMIN, $reloaded->getRoles());
    }

    // Verifies promoteToAdmin replaces the role list with ROLE_ADMIN.
    public function testPromoteToAdminSetsRoleAdmin(): void
    {
        $user = $this->userRepository->findOneBy(['email' => UserFixtures::BOB_EMAIL]);
        self::assertNotNull($user);

        $this->userRoles->promoteToAdmin($user);

        $reloaded = $this->userRepository->find($user->getId());
        self::assertNotNull($reloaded);
        self::assertContains(Roles::ADMIN, $reloaded->getRoles());
    }

    // Verifies removeAllPermissions clears the elevated roles, leaving the implicit ROLE_USER floor.
    public function testRemoveAllPermissionsClearsElevatedRoles(): void
    {
        $user = $this->userRepository->findOneBy(['email' => UserFixtures::DOMAIN_MANAGER_EMAIL]);
        self::assertNotNull($user);

        $this->userRoles->removeAllPermissions($user);

        $reloaded = $this->userRepository->find($user->getId());
        self::assertNotNull($reloaded);
        self::assertNotContains(Roles::DOMAIN_MANAGER, $reloaded->getRoles());
        self::assertNotContains(Roles::ADMIN, $reloaded->getRoles());
    }

    // Tests that the last-admin guard refuses to demote the only remaining admin.
    public function testRemoveAllPermissionsRefusesWhenTargetIsLastAdmin(): void
    {
        // Establish a known single-admin world by promoting one user
        // and (relying on the suite's DAMA rollback) trusting no other
        // admins are present from the baseline.
        $soloAdmin = $this->ensureSoleAdmin();

        $this->expectException(LastAdminException::class);

        $this->userRoles->removeAllPermissions($soloAdmin);
    }

    // Tests that the last-admin guard also fires on promoteToManager when the only admin is being moved aside.
    public function testPromoteToManagerRefusesWhenTargetIsLastAdmin(): void
    {
        $soloAdmin = $this->ensureSoleAdmin();

        $this->expectException(LastAdminException::class);

        $this->userRoles->promoteToManager($soloAdmin);
    }

    // Verifies that with two admins the guard allows the demotion to proceed.
    public function testRemoveAllPermissionsAllowsWhenAnotherAdminRemains(): void
    {
        $first = $this->userManager->createUser('admin-a@example.test', 'A', 'pw', [Roles::ADMIN]);
        $second = $this->userManager->createUser('admin-b@example.test', 'B', 'pw', [Roles::ADMIN]);

        $this->userRoles->removeAllPermissions($first);

        $reloaded = $this->userRepository->find($first->getId());
        self::assertNotNull($reloaded);
        self::assertNotContains(Roles::ADMIN, $reloaded->getRoles());

        // The other admin is untouched, so the site keeps a sentinel.
        $other = $this->userRepository->find($second->getId());
        self::assertNotNull($other);
        self::assertContains(Roles::ADMIN, $other->getRoles());
    }

    /**
     * Demote every existing admin to a plain user, then promote a
     * fresh one. Returns the lone surviving admin.
     */
    private function ensureSoleAdmin(): \App\Entity\User
    {
        // Strip admin from every existing admin row so countAdmins()
        // starts from zero, then mint the test's lone admin.
        foreach ($this->userRepository->findAll() as $existing) {
            if (\in_array(Roles::ADMIN, $existing->getRoles(), true)) {
                $existing->setRoles([]);
            }
        }
        self::getContainer()->get('doctrine')->getManager()->flush();

        return $this->userManager->createUser('solo-admin@example.test', 'Solo', 'pw', [Roles::ADMIN]);
    }
}
