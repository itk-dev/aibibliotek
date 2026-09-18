<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\OrganizationRepository;

/**
 * Allow-list of email domains accepted by anonymous self-signup.
 *
 * Sourced live from the `Organization.emailDomains` column —
 * adding or editing an organisation through `/admin/organization`
 * flips a domain on or off without a redeploy. Domains are
 * normalised to lowercase + trimmed inside the repository query
 * so `aarhus.dk`, `Aarhus.DK`, and `  AARHUS.dk` all match the
 * same way.
 *
 * A fresh install with no organisations rejects every signup —
 * operators are expected to seed at least one organisation
 * before opening signup. The admin UI at `/admin/organization`
 * (or the `OrganizationFixtures` for local dev) is the intended
 * way to populate the list.
 */
final class AllowedEmailDomains
{
    /**
     * @param OrganizationRepository $organizationRepository read-side lookup of every organisation's email domains
     */
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
    ) {
    }

    /**
     * Check whether `$domain` matches any organisation's allow-list.
     *
     * The comparison is case-insensitive and tolerates surrounding
     * whitespace, mirroring the normalisation
     * {@see OrganizationRepository::collectAllowedEmailDomains()}
     * applies to the stored entries.
     *
     * @param string $domain candidate domain to check (e.g. `aarhus.dk`)
     *
     * @return bool whether the domain is on at least one organisation's allow-list
     */
    public function contains(string $domain): bool
    {
        return \in_array(
            strtolower(trim($domain)),
            $this->organizationRepository->collectAllowedEmailDomains(),
            true,
        );
    }

    /**
     * Return the full normalised allow-list.
     *
     * Useful for diagnostics, admin-facing pages, and tests that
     * want to assert "this domain became allowed when the org was
     * created". The list is freshly fetched on every call — callers
     * that need to inspect it many times in one request should
     * snapshot the return value locally.
     *
     * @return list<string> the deduped, lowercased domains every organisation row contributes
     */
    public function all(): array
    {
        return $this->organizationRepository->collectAllowedEmailDomains();
    }
}
