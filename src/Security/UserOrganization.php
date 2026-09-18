<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;

/**
 * Resolves the {@see Organization} a {@see User} belongs to.
 *
 * Users carry no organisation column: organisational membership is
 * derived from the right-hand side of the user's e-mail address and
 * matched against the `email_domains` list each organisation
 * declares. {@see EmailDomain} owns the extraction, this service owns
 * the lookup, so every surface that needs "which municipality is this
 * operator acting for?" asks the same question in one place.
 *
 * A user whose domain no organisation claims resolves to `null`. That
 * is a legitimate state — registration only checks the domain against
 * the union allow-list, and an organisation row can be deleted after
 * the fact — so callers must fail closed rather than assume a match.
 */
final class UserOrganization
{
    /**
     * @param OrganizationRepository $organizations repository the domain lookup goes through
     */
    public function __construct(
        private readonly OrganizationRepository $organizations,
    ) {
    }

    /**
     * Resolve the organisation claiming this user's e-mail domain.
     *
     * @param User $user the user whose organisation to resolve
     *
     * @return Organization|null the claiming organisation, or `null` when the
     *                           user has no usable e-mail domain or no
     *                           organisation declares it
     */
    public function of(User $user): ?Organization
    {
        $domain = EmailDomain::of($user);
        if (null === $domain) {
            return null;
        }

        return $this->organizations->findOneByEmailDomain($domain);
    }

    /**
     * Tell whether the user acts for the given organisation.
     *
     * Compares by persisted identity rather than object identity so a
     * detached or re-fetched instance still matches. Fails closed on
     * either side being null or unpersisted — an operator without a
     * resolvable organisation covers nothing.
     *
     * @param User              $user         the acting user
     * @param Organization|null $organization the organisation being acted on
     *
     * @return bool true when the user's derived organisation is the given one
     */
    public function covers(User $user, ?Organization $organization): bool
    {
        if (null === $organization) {
            return false;
        }

        $actorOrganization = $this->of($user);
        if (null === $actorOrganization) {
            return false;
        }

        return (bool) $actorOrganization->getId()?->equals($organization->getId());
    }
}
