<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Enum\UserStatus;
use App\Security\Roles;
use App\Security\UserManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Seed baseline users covering every `Roles::*` and every
 * `UserStatus` case so local development and integration tests
 * can exercise the role-gated screens, the domain-scoped
 * voter, and the status-aware login checker out of the box.
 *
 * Every seed shares the plain password `password` for
 * paste-friendly local login. The two existing accounts —
 * `alice@example.test` and `bob@example.test` — are kept as
 * the cross-fixture lookup points used by
 * {@see FixtureCreators}; the remaining accounts pick e-mail
 * domains that line up with {@see OrganizationFixtures} so
 * `ManageUserVoter` has something to scope against.
 */
final class UserFixtures extends Fixture implements FixtureGroupInterface
{
    /**
     * Belong to the `default` group so the Woodpecker stg pipeline can
     * load the general fixture set without also seeding
     * {@see LocalUserFixtures}' personal-inbox accounts (`--group=default`
     * then `--group=local --append`).
     *
     * @return list<string> group identifiers the fixtures bundle filters on
     */
    public static function getGroups(): array
    {
        return ['default'];
    }

    /**
     * E-mail of the first baseline user, shared with the fixtures that look
     * her up to assign as a creating user.
     */
    public const string ALICE_EMAIL = 'alice@example.test';

    /**
     * E-mail of the second baseline user, shared with the fixtures that look
     * him up to assign as a creating user.
     */
    public const string BOB_EMAIL = 'bob@example.test';

    /**
     * Site-wide administrator (`ROLE_ADMIN`, status `Approved`).
     */
    public const string ADMIN_EMAIL = 'admin@aarhus.dk';

    /**
     * Domain manager scoped to `aarhus.dk` (`ROLE_DOMAIN_MANAGER`,
     * status `Approved`).
     */
    public const string DOMAIN_MANAGER_EMAIL = 'manager@aarhus.dk';

    /**
     * Second domain manager sharing the `aarhus.dk` scope. Lets tests
     * exercise "one event fans out to every same-domain manager"
     * paths (notably the domain-manager registration notifier)
     * without inline `createUser` plumbing.
     */
    public const string SECOND_DOMAIN_MANAGER_EMAIL = 'manager2@aarhus.dk';

    /**
     * Plain `Approved` user sharing the manager's `aarhus.dk` domain.
     * Lets tests exercise "manager acts on a same-domain non-admin"
     * scenarios without inline `createUser` plumbing — the rest of
     * the seeded `aarhus.dk` row is the admin, whom the manager can
     * never touch.
     */
    public const string COLLEAGUE_EMAIL = 'colleague@aarhus.dk';

    /**
     * Account awaiting moderator approval (default role,
     * status `Pending`).
     */
    public const string PENDING_EMAIL = 'pending@aalborg.dk';

    /**
     * Account awaiting e-mail confirmation (default role,
     * status `AwaitingEmailConfirmation`).
     */
    public const string AWAITING_EMAIL = 'awaiting@aalborg.dk';

    /**
     * Blocked account (default role, status `Blocked`).
     */
    public const string BLOCKED_EMAIL = 'blocked@odense.dk';

    /**
     * @param UserManager $userManager service that creates the persisted users
     */
    public function __construct(private readonly UserManager $userManager)
    {
    }

    /**
     * Persist the baseline users via {@see UserManager::createUser()}.
     *
     * The two original `example.test` accounts seed the
     * cross-fixture relations; the remaining accounts pick domains
     * already declared by `OrganizationFixtures` so role-scoped
     * voters and admin screens have a realistic dataset. Aarhus
     * carries two domain managers so tests can exercise fan-out to
     * every same-domain manager.
     *
     * @param ObjectManager $manager unused — UserManager flushes its own entity manager
     */
    public function load(ObjectManager $manager): void
    {
        $this->userManager->createUser(self::ALICE_EMAIL, 'Alice', 'password', status: UserStatus::Approved);
        $this->userManager->createUser(self::BOB_EMAIL, 'Bob', 'password', status: UserStatus::Approved);
        $this->userManager->createUser(self::ADMIN_EMAIL, 'Admin', 'password', [Roles::ADMIN], UserStatus::Approved);
        $this->userManager->createUser(self::DOMAIN_MANAGER_EMAIL, 'Manager', 'password', [Roles::DOMAIN_MANAGER], UserStatus::Approved);
        $this->userManager->createUser(self::SECOND_DOMAIN_MANAGER_EMAIL, 'Manager Two', 'password', [Roles::DOMAIN_MANAGER], UserStatus::Approved);
        $this->userManager->createUser(self::COLLEAGUE_EMAIL, 'Colleague', 'password', status: UserStatus::Approved);
        $this->userManager->createUser(self::PENDING_EMAIL, 'Pending', 'password', status: UserStatus::Pending);
        $this->userManager->createUser(self::AWAITING_EMAIL, 'Awaiting', 'password', status: UserStatus::AwaitingEmailConfirmation);
        $this->userManager->createUser(self::BLOCKED_EMAIL, 'Blocked', 'password', status: UserStatus::Blocked);
    }
}
