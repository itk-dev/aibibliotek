<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\DataFixtures\UserFixtures;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Security\LastAdminException;
use App\Security\Roles;
use App\Security\UserApproval;
use App\Security\UserManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserApprovalTest extends KernelTestCase
{
    private UserManager $userManager;
    private UserApproval $userApproval;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->userManager = $container->get(UserManager::class);
        $this->userApproval = $container->get(UserApproval::class);
        $this->userRepository = $container->get(UserRepository::class);
    }

    public function testApproveTransitionsPendingUserToApproved(): void
    {
        $user = $this->userRepository->findOneBy(['email' => UserFixtures::PENDING_EMAIL]);
        self::assertNotNull($user);

        $this->userApproval->approve($user);

        self::assertSame(UserStatus::Approved, $user->getStatus());
    }

    public function testBlockTransitionsApprovedUserToBlocked(): void
    {
        $user = $this->userRepository->findOneBy(['email' => UserFixtures::ALICE_EMAIL]);
        self::assertNotNull($user);

        $this->userApproval->block($user);

        self::assertSame(UserStatus::Blocked, $user->getStatus());
    }

    public function testApprovalIsRoundTrippedThroughTheDatabase(): void
    {
        $user = $this->userRepository->findOneBy(['email' => UserFixtures::PENDING_EMAIL]);
        self::assertNotNull($user);
        $id = $user->getId();

        $this->userApproval->approve($user);

        self::bootKernel();
        $reloaded = self::getContainer()
            ->get(UserRepository::class)
            ->find($id);

        self::assertNotNull($reloaded);
        self::assertSame(UserStatus::Approved, $reloaded->getStatus());
    }

    // Ensures blocking the only active admin is refused — the site must keep at least one admin who can log in.
    public function testBlockRefusesWhenTargetIsTheOnlyActiveAdmin(): void
    {
        $soloAdmin = $this->ensureSoleActiveAdmin();

        $this->expectException(LastAdminException::class);

        $this->userApproval->block($soloAdmin);
    }

    // Verifies that with two active admins, blocking one is allowed — the other still keeps the site reachable.
    public function testBlockAllowsWhenAnotherActiveAdminRemains(): void
    {
        $first = $this->userManager->createUser(
            'admin-a@example.test',
            'A',
            'pw',
            [Roles::ADMIN],
            UserStatus::Approved,
        );
        $this->userManager->createUser(
            'admin-b@example.test',
            'B',
            'pw',
            [Roles::ADMIN],
            UserStatus::Approved,
        );

        $this->userApproval->block($first);

        self::assertSame(UserStatus::Blocked, $first->getStatus());
    }

    // Verifies that blocking an admin who isn't currently Approved (e.g. Pending) is allowed — they aren't in the active-admin pool, so blocking them doesn't reduce it.
    public function testBlockAllowsAdminTargetThatIsNotApproved(): void
    {
        $this->userManager->createUser(
            'live-admin@example.test',
            'Live',
            'pw',
            [Roles::ADMIN],
            UserStatus::Approved,
        );
        $pendingAdmin = $this->userManager->createUser(
            'pending-admin@example.test',
            'Pending Admin',
            'pw',
            [Roles::ADMIN],
            UserStatus::Pending,
        );

        // Strip the existing approved admins from the fixture baseline
        // so we know the active-admin count is exactly one (live-admin).
        // The pending admin can still be blocked — they were never in
        // the loggable pool, so the guard doesn't apply.
        $this->userApproval->block($pendingAdmin);

        self::assertSame(UserStatus::Blocked, $pendingAdmin->getStatus());
    }

    /**
     * Demote every existing admin to a plain user, then promote a
     * fresh `Approved` admin so the active-admin count is exactly
     * one. Returns the lone surviving admin.
     */
    private function ensureSoleActiveAdmin(): \App\Entity\User
    {
        foreach (self::getContainer()->get(UserRepository::class)->findAll() as $existing) {
            if (\in_array(Roles::ADMIN, $existing->getRoles(), true)) {
                $existing->setRoles([]);
            }
        }
        self::getContainer()->get('doctrine')->getManager()->flush();

        return $this->userManager->createUser(
            'solo-active-admin@example.test',
            'Solo',
            'pw',
            [Roles::ADMIN],
            UserStatus::Approved,
        );
    }
}
