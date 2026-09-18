<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Symfony Security role identifiers used by this application.
 *
 * `ROLE_ADMIN` implies `ROLE_DOMAIN_MANAGER` via
 * the `role_hierarchy` entry in `security.yaml`. `ROLE_USER` is the
 * implicit floor: every authenticated user holds it via
 * {@see \App\Entity\User::getRoles()}, so this constant exists only
 * to remove magic strings from `IsGranted` attributes and voter
 * call-sites.
 */
final class Roles
{
    /**
     * The implicit floor every authenticated user holds.
     */
    public const string USER = 'ROLE_USER';

    /**
     * Authority to approve / block accounts within the manager's own
     * email domain. Holders can also see the scoped admin user list.
     */
    public const string DOMAIN_MANAGER = 'ROLE_DOMAIN_MANAGER';

    /**
     * Site-wide admin. Implies `ROLE_DOMAIN_MANAGER` across every
     * email domain via the role hierarchy.
     */
    public const string ADMIN = 'ROLE_ADMIN';
}
