<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Read the authenticated user as a concrete {@see User}.
 *
 * `Security::getUser()` and `AbstractController::getUser()` are both
 * typed to `?UserInterface`, so every caller behind a firewall has to
 * re-narrow the result before it can reach an application method. That
 * narrowing used to be written as `\assert($user instanceof User)`,
 * which documents the expectation without enforcing it: both container
 * images run `zend.assertions=-1`, so the assert is stripped at compile
 * time and a null would surface further down as a confusing
 * "call to a member function on null".
 *
 * Centralising it means the check exists once, runs for real, and is
 * covered by tests — rather than being repeated at every call site in a
 * form that never executes.
 */
final readonly class CurrentUser
{
    /**
     * @param Security $security the security context the user is read from
     */
    public function __construct(
        private Security $security,
    ) {
    }

    /**
     * Return the authenticated user.
     *
     * Callers are expected to sit behind a firewall — `IsGranted` on the
     * controller, or an equivalent access rule — so an unauthenticated
     * request should never reach this point. When one does, that is a
     * routing or security-configuration mistake rather than a runtime
     * condition to recover from, which is why it raises rather than
     * returning null.
     *
     * @return User the authenticated user
     *
     * @throws \LogicException when no user is authenticated, or the token
     *                         holds a user type this application does not issue
     */
    public function get(): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('Expected an authenticated %s, got %s. The caller must sit behind a firewall.', User::class, get_debug_type($user)));
        }

        return $user;
    }
}
