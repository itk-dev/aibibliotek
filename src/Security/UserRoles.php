<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Owns role-mutation transitions on the {@see User} entity:
 * `Promote to Manager`, `Promote to Admin`, and `Remove all
 * permissions`. Centralising the writes here means there is one
 * authoritative answer to "what roles does a user end up with after
 * action X", and one place to add audit logging or change
 * notification if those land later.
 *
 * Authorisation is enforced by {@see Voter\ManageUserVoter}
 * before any call into this service reaches it — callers must
 * already have passed an `IsGranted` check. The service is
 * privilege-aware in only one way: it refuses to demote the last
 * remaining `ROLE_ADMIN`, since doing so would brick the install.
 * That single resource-state invariant lives here, not in the
 * voter (a voter authorises an action, the service refuses to
 * apply one that the data model can't survive).
 */
final class UserRoles
{
    /**
     * @param UserManager    $userManager    delegates the actual persistence (validates roles, flushes once)
     * @param UserRepository $userRepository read-side lookup used to count remaining admins for the last-admin guard
     */
    public function __construct(
        private readonly UserManager $userManager,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Set the user's role list to `[ROLE_DOMAIN_MANAGER]`.
     *
     * Idempotent: re-promoting an existing manager is a no-op flush.
     * When promoting away from `ROLE_ADMIN`, the last-admin guard
     * still applies — the site must keep at least one admin.
     *
     * @param User $user the user to promote (or sideways-move) to manager
     *
     * @throws LastAdminException        when `$user` is the only remaining admin
     * @throws \DomainException          when {@see UserManager::updateUser()} cannot find a row for the user's email
     * @throws \InvalidArgumentException when the role list is somehow rejected by {@see UserManager::updateUser()} (should not happen with the hard-coded constants below)
     */
    public function promoteToManager(User $user): void
    {
        $this->guardAgainstLastAdminDemotion($user);

        $this->userManager->updateUser(
            (string) $user->getEmail(),
            roles: [Roles::DOMAIN_MANAGER],
        );
    }

    /**
     * Set the user's role list to `[ROLE_ADMIN]`.
     *
     * `ROLE_ADMIN` implies `ROLE_DOMAIN_MANAGER` via the configured
     * role hierarchy, so we store only the higher role and let
     * Symfony's hierarchy resolution fan it out at runtime.
     * Idempotent on a user who is already an admin.
     *
     * @param User $user the user to promote to admin
     *
     * @throws \DomainException          when {@see UserManager::updateUser()} cannot find a row for the user's email
     * @throws \InvalidArgumentException when the role list is somehow rejected by {@see UserManager::updateUser()} (should not happen with the hard-coded constants below)
     */
    public function promoteToAdmin(User $user): void
    {
        $this->userManager->updateUser(
            (string) $user->getEmail(),
            roles: [Roles::ADMIN],
        );
    }

    /**
     * Clear every elevated role, leaving the user with the implicit
     * `ROLE_USER` floor only.
     *
     * Idempotent on a user who already has no elevated roles. The
     * last-admin guard applies: if the user is the only remaining
     * admin, the call throws and nothing is persisted.
     *
     * @param User $user the user whose elevated roles should be cleared
     *
     * @throws LastAdminException        when `$user` is the only remaining admin
     * @throws \DomainException          when {@see UserManager::updateUser()} cannot find a row for the user's email
     * @throws \InvalidArgumentException when the role list is somehow rejected by {@see UserManager::updateUser()} (should not happen with the hard-coded constants below)
     */
    public function removeAllPermissions(User $user): void
    {
        $this->guardAgainstLastAdminDemotion($user);

        $this->userManager->updateUser(
            (string) $user->getEmail(),
            roles: [],
        );
    }

    /**
     * Throw if the transition about to be applied would demote the
     * last remaining `ROLE_ADMIN`. Counts admins via the repository;
     * the comparison is "user is currently an admin AND total admin
     * count is one" — the only configuration in which removing this
     * row's admin role locks the site.
     *
     * @param User $user the user about to be demoted
     *
     * @throws LastAdminException when the demotion would leave the site without an admin
     */
    private function guardAgainstLastAdminDemotion(User $user): void
    {
        if (!\in_array(Roles::ADMIN, $user->getRoles(), true)) {
            return;
        }

        if ($this->userRepository->countAdmins() <= 1) {
            throw new LastAdminException(\sprintf('Refusing to demote "%s": the site would be left without an administrator.', (string) $user->getEmail()));
        }
    }
}
