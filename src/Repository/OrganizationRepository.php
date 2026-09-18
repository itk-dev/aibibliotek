<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Organization>
 */
class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    /**
     * Look up the organisation that claims the given e-mail domain.
     *
     * The `email_domains` column is a JSON list, and MariaDB / MySQL
     * cannot equality-index into JSON values from Doctrine's DQL layer
     * without extension functions. Load the small candidate set and
     * scan in PHP — the `organization` table is expected to stay
     * small (one row per municipality). Matching is case-insensitive
     * against the trimmed input.
     *
     * @param string $domain the domain to match, e.g. `aarhus.dk`
     *
     * @return Organization|null the first organisation claiming the
     *                           domain, or `null` when no row matches
     *                           or the input trims to empty
     */
    public function findOneByEmailDomain(string $domain): ?Organization
    {
        $normalised = strtolower(trim($domain));
        if ('' === $normalised) {
            return null;
        }

        foreach ($this->findAll() as $organization) {
            if (\in_array($normalised, $organization->getEmailDomains(), true)) {
                return $organization;
            }
        }

        return null;
    }

    /**
     * Flatten every organisation's `emailDomains` into the union
     * allow-list that {@see \App\Security\AllowedEmailDomains}
     * consults at registration time.
     *
     * Each domain is trimmed and lowercased before deduplication so
     * an admin's typing convention (`Aarhus.DK` vs `aarhus.dk`)
     * doesn't produce two entries. Empty strings — which would
     * otherwise short-circuit the domain comparison in
     * `Registration` — are filtered out. Returns a re-indexed list
     * so callers can `in_array(..., strict: true)` against it
     * without worrying about gaps.
     *
     * @return list<string> the deduped, lowercased domains every organisation row contributes
     */
    public function collectAllowedEmailDomains(): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('o.emailDomains')
            ->getQuery()
            ->getArrayResult();

        $domains = [];
        foreach ($rows as $row) {
            foreach ((array) ($row['emailDomains'] ?? []) as $domain) {
                $normalised = strtolower(trim((string) $domain));
                if ('' === $normalised) {
                    continue;
                }
                $domains[$normalised] = true;
            }
        }

        return array_keys($domains);
    }
}
