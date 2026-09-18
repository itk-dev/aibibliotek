<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * Pure helpers for extracting an organisation-identifying domain from
 * a {@see User}'s email address.
 *
 * The "domain manager" model derives a user's organisational
 * scope from the right-hand side of their email rather than carrying a
 * separate column. Both the voter ({@see Voter\ManageUserVoter}) and
 * user-management repository need th same normalisation, so it lives here in
 * one place.
 */
final class EmailDomain
{
    /**
     * Extract the lowercased domain from a user's email.
     *
     * Returns `null` when the user has no email set or when the email
     * has no `@` (defensive — both cases should be unreachable for a
     * persisted user, but the caller's authorisation decision must
     * still fail closed if they happen).
     *
     * @param User $user the user whose email to inspect
     *
     * @return string|null lowercased domain ("aarhus.dk") or `null` when undeterminable
     */
    public static function of(User $user): ?string
    {
        $email = $user->getEmail();
        if (null === $email) {
            return null;
        }

        $at = strrpos($email, '@');
        if (false === $at || $at === strlen($email) - 1) {
            return null;
        }

        return strtolower(substr($email, $at + 1));
    }
}
