<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Reject login attempts for any {@see User} whose {@see UserStatus} is
 * not {@see UserStatus::Approved}.
 *
 * Wired on the `main` firewall via `security.yaml`'s `user_checker:`
 * key. Symfony Security calls {@see self::checkPreAuth()} before the
 * password is verified; throwing a
 * {@see CustomUserMessageAccountStatusException} halts the flow and
 * surfaces the (localised) translation key on the login form.
 *
 * A `Blocked` user retains the roles
 * they had before being blocked — they just can't sign in to exercise
 * them.
 */
final class AccountStatusChecker implements UserCheckerInterface
{
    /**
     * Refuse any non-approved user before the password is checked.
     *
     * Non-`User` implementations fall through (the password checker
     * will reject them on its own terms).
     *
     * @param UserInterface       $user  the user attempting to authenticate
     * @param TokenInterface|null $token unused; Symfony 8 added the slot for hooks that need it
     *
     * @throws CustomUserMessageAccountStatusException when status is AwaitingEmailConfirmation, Pending or Blocked
     */
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        // Keys live in the `security` translation domain — see
        // translations/security.da.yaml.
        match ($user->getStatus()) {
            UserStatus::AwaitingEmailConfirmation => throw new CustomUserMessageAccountStatusException('account.awaiting_email_confirmation'),
            UserStatus::Pending => throw new CustomUserMessageAccountStatusException('account.pending'),
            UserStatus::Blocked => throw new CustomUserMessageAccountStatusException('account.blocked'),
            UserStatus::Approved => null,
        };
    }

    /**
     * Post-auth hook required by the interface; no checks needed here.
     *
     * @param UserInterface       $user  the user that just authenticated successfully
     * @param TokenInterface|null $token unused; Symfony 8 added the slot for hooks that need it
     */
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
