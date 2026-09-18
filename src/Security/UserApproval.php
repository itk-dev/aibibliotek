<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns the `Pending → Approved` / `Approved → Blocked` / `Blocked →
 * Approved` transitions used by the admin approval queue and the
 * scoped user-management list.
 *
 * The controller calls one of {@see self::approve()} or
 * {@see self::block()}; nothing else mutates `User::$status` after
 * the initial value is set at construction (the registration flow
 * creates `Pending` users, the console / fixture path creates
 * `Approved`). Keeping the writes in this single service means
 * there is one place to add audit logging or change notification
 * if those land later.
 *
 * The block path is gated by a last-active-admin guard: blocking
 * an admin whose status is currently `Approved` while no other
 * admin is `Approved` would lock the site, so the service throws
 * a {@see LastAdminException} and refuses to flush. The voter
 * cannot enforce this — voters authorise an action, the service
 * refuses to apply one the data model can't survive.
 */
final class UserApproval
{
    /**
     * @param EntityManagerInterface $entityManager  Doctrine entity manager used to flush the status change
     * @param UserRepository         $userRepository read-side lookup used to count active admins for the last-admin guard
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Mark the user as approved so they may sign in.
     *
     * Safe to call when the user is already `Approved` — the flush
     * becomes a no-op.
     *
     * @param User $user the user to approve
     */
    public function approve(User $user): void
    {
        $user->setStatus(UserStatus::Approved);
        $this->entityManager->flush();
    }

    /**
     * Mark the user as blocked so they cannot sign in (but their row
     * is preserved for audit and possible un-blocking).
     *
     * Safe to call when the user is already `Blocked` — the flush
     * becomes a no-op.
     *
     * Refuses to block the last remaining active admin. The check is
     * conservative: it fires when the target currently holds
     * `ROLE_ADMIN`, their status is `Approved` (so blocking actually
     * removes them from the active-admin pool), and the active-admin
     * count is at most one. A `LastAdminException` is thrown without
     * touching the entity — the controller catches it and surfaces
     * a flash error.
     *
     * @param User $user the user to block
     *
     * @throws LastAdminException when blocking would leave the site with no active administrator
     */
    public function block(User $user): void
    {
        $this->guardAgainstBlockingLastActiveAdmin($user);

        $user->setStatus(UserStatus::Blocked);
        $this->entityManager->flush();
    }

    /**
     * Throw if blocking `$user` would empty the active-admin pool.
     *
     * Counts users via {@see UserRepository::countActiveAdmins()}.
     * A target whose status is not currently `Approved` is exempt:
     * blocking them is either a no-op (`Blocked → Blocked`) or a
     * transition between two non-loggable states (`Pending →
     * Blocked`), neither of which reduces the loggable admin
     * count.
     *
     * @param User $user the user about to be blocked
     *
     * @throws LastAdminException when the block would leave the site without an active administrator
     */
    private function guardAgainstBlockingLastActiveAdmin(User $user): void
    {
        if (!\in_array(Roles::ADMIN, $user->getRoles(), true)) {
            return;
        }

        if (UserStatus::Approved !== $user->getStatus()) {
            return;
        }

        if ($this->userRepository->countActiveAdmins() <= 1) {
            throw new LastAdminException(\sprintf('Refusing to block "%s": the site would be left without an active administrator.', (string) $user->getEmail()));
        }
    }
}
