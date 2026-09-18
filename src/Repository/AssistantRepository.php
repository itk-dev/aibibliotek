<?php

declare(strict_types=1);

namespace App\Repository;

use App\Catalog\CatalogCriteria;
use App\Catalog\CatalogSort;
use App\Entity\Assistant;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\DataSensitivity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Assistant>
 */
class AssistantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assistant::class);
    }

    /**
     * List every assistant this user is the `createdBy` blame for.
     *
     * Backs the "Mine assistenter" page — the operator's personal
     * inventory of assistants they've shared. Ordered newest-first
     * so the freshly-uploaded row is at the top; `id` DESC breaks
     * ties deterministically when several rows share a timestamp
     * (fixtures typically do).
     *
     * @param User $user the acting user whose creations we want
     *
     * @return list<Assistant> assistants stamped with `createdBy = $user`, newest first
     */
    public function findCreatedBy(User $user): array
    {
        // Bind by identifier rather than the entity — the mapping
        // on BlameableTrait uses `targetEntity: UserInterface::class`
        // with `resolve_target_entities`, which makes DQL's entity
        // comparison ambiguous. `IDENTITY()` extracts the raw FK
        // value; passing the `Ulid` object lets Doctrine's `ulid`
        // type convert it to the stored binary form.
        /** @var list<Assistant> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.createdBy) = :userId')
            ->setParameter('userId', $user->getId(), 'ulid')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * List every assistant shared by the given organisation.
     *
     * Backs the admin ownership screen, where a domain manager
     * reviews and reassigns the assistants their municipality owns.
     * Ordered by title so the operator scans an alphabetical list
     * rather than an upload-order one; `id` ASC breaks ties
     * deterministically when two assistants share a title.
     *
     * @param Organization $organization the organisation whose assistants to list
     *
     * @return list<Assistant> assistants stamped with `organization = $organization`, A→Z by title
     */
    public function findByOrganization(Organization $organization): array
    {
        /** @var list<Assistant> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.organization) = :organizationId')
            ->setParameter('organizationId', $organization->getId(), 'ulid')
            ->orderBy('a.title', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Count how many distinct `languageModel` values are in use across
     * the catalogue. Powers the frontpage "Sprogmodeller" stat.
     */
    public function countDistinctLanguageModels(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(DISTINCT a.languageModel)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * List every distinct non-empty `languageModel` value stored in the
     * catalogue.
     *
     * Feeds the metadata step's model picker so the dropdown can offer
     * legacy or free-typed values already in use — even when they are
     * not present in `config/model_map.yaml`'s canonical shortlist. The
     * caller merges this with the canonical list before rendering.
     *
     * @return list<string> distinct non-empty stored ids, ordered A→Z
     *
     * @throws \Doctrine\DBAL\Exception when the underlying connection or query execution fails
     */
    public function persistedLanguageModels(): array
    {
        /** @var list<array{languageModel: string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.languageModel AS languageModel')
            ->andWhere("a.languageModel <> ''")
            ->orderBy('a.languageModel', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['languageModel'], $rows);
    }

    /**
     * Paginated catalogue listing filtered by the given criteria.
     *
     * Each facet selection on the criteria is an OR-within / AND-across
     * set: a non-empty `languageModels` keeps rows whose `languageModel`
     * is in that list, AND a non-empty `frameworks` further narrows on
     * `framework`, AND a non-empty `tags` keeps rows carrying at least
     * one of the named tags. An empty facet means "no filter on this
     * facet". A non-empty `q` further narrows to rows whose title or
     * description contains the query (case-insensitive substring).
     * Results are ordered according to `criteria->sort` (see
     * {@see self::applySort()}), with `id` as a tiebreaker so the order
     * is stable even when the primary sort key ties.
     *
     * The tag filter is expressed as an `id IN (subquery)` over the
     * `assistant_tag` join rather than a fetch join, so multiple selected
     * tags OR together without inflating the row count and breaking the
     * paginator's LIMIT/OFFSET maths.
     *
     * @param CatalogCriteria $criteria the user's filter selections
     * @param int             $page     1-based page number; clamped to `>= 1` by the caller
     * @param int             $perPage  results per page; must be `>= 1`
     *
     * @return Paginator<Assistant>
     *
     * @throws \Doctrine\DBAL\Exception when the underlying connection or query execution fails
     */
    public function findPaginated(CatalogCriteria $criteria, int $page, int $perPage): Paginator
    {
        $qb = $this->createQueryBuilder('a');
        $this->applySort($qb, $criteria->sort);

        if (null !== $criteria->q) {
            // Parenthesise the OR so it binds as a unit when ANDed with the
            // facet clauses below — otherwise SQL precedence would read it as
            // `title LIKE … OR (description LIKE … AND facet …)`.
            $qb->andWhere('(LOWER(a.title) LIKE :q OR LOWER(a.description) LIKE :q)')
                ->setParameter('q', '%'.mb_strtolower($criteria->q).'%');
        }

        if ([] !== $criteria->languageModels) {
            $qb->andWhere('a.languageModel IN (:languageModels)')
                ->setParameter('languageModels', $criteria->languageModels);
        }

        if ([] !== $criteria->frameworks) {
            $qb->andWhere('a.framework IN (:frameworks)')
                ->setParameter('frameworks', $criteria->frameworks);
        }

        if ([] !== $criteria->tags) {
            $qb->andWhere($qb->expr()->in(
                'a.id',
                $this->createQueryBuilder('a2')
                    ->select('a2.id')
                    ->join('a2.tags', 't')
                    ->andWhere('t.name IN (:tags)')
                    ->getDQL(),
            ))->setParameter('tags', $criteria->tags);
        }

        if ([] !== $criteria->organizations) {
            // Same `IN (subquery)` shape as the tag filter: the
            // organisation is a to-one relation, so a join here would not
            // multiply rows, but keeping the form consistent means the
            // paginator's LIMIT/OFFSET maths never has to care which
            // filters happen to be active.
            $qb->andWhere($qb->expr()->in(
                'a.id',
                $this->createQueryBuilder('a3')
                    ->select('a3.id')
                    ->join('a3.organization', 'o')
                    ->andWhere('o.name IN (:organizations)')
                    ->getDQL(),
            ))->setParameter('organizations', $criteria->organizations);
        }

        if ([] !== $criteria->dataSensitivities) {
            $qb->andWhere('a.dataSensitivity IN (:dataSensitivities)')
                ->setParameter('dataSensitivities', $criteria->dataSensitivities);
        }

        $qb->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return new Paginator($qb->getQuery(), false);
    }

    /**
     * Apply the catalogue ordering for the given sort to a query builder.
     *
     * Each case sets the primary `ORDER BY` and then appends `a.id` in the
     * same direction as a tiebreaker, so rows that tie on the primary key
     * (e.g. the timestamp sorts, since fixtures share a creation instant)
     * still come back in a deterministic, paginatable order. Name sorts
     * tiebreak on `a.id ASC` regardless of name direction — the tiebreak
     * only needs to be stable, not aligned with the title direction.
     *
     * @param QueryBuilder $qb   the catalogue query under construction
     * @param CatalogSort  $sort the ordering the user asked for
     */
    private function applySort(QueryBuilder $qb, CatalogSort $sort): void
    {
        match ($sort) {
            CatalogSort::Newest => $qb->orderBy('a.createdAt', 'DESC')->addOrderBy('a.id', 'DESC'),
            CatalogSort::Oldest => $qb->orderBy('a.createdAt', 'ASC')->addOrderBy('a.id', 'ASC'),
            CatalogSort::RecentlyUpdated => $qb->orderBy('a.updatedAt', 'DESC')->addOrderBy('a.id', 'DESC'),
            CatalogSort::NameAsc => $qb->orderBy('a.title', 'ASC')->addOrderBy('a.id', 'ASC'),
            CatalogSort::NameDesc => $qb->orderBy('a.title', 'DESC')->addOrderBy('a.id', 'ASC'),
        };
    }

    /**
     * Count assistants grouped by their `languageModel` value.
     *
     * Powers the catalogue's "Sprogmodel" facet — label + per-bucket
     * count. Returns counts across the full catalogue (not narrowed by
     * the active filter set) so the user can always see what other
     * buckets are reachable from the current state.
     *
     * @return array<string, int> ordered by count DESC then value ASC; key is the language-model string
     *
     * @throws \Doctrine\DBAL\Exception when the underlying connection or query execution fails
     */
    public function languageModelFacetCounts(): array
    {
        return $this->facetCounts('languageModel');
    }

    /**
     * Count assistants grouped by their `framework` value.
     *
     * Powers the catalogue's "Rammeværk" facet — label + per-bucket
     * count, computed against the full catalogue for the same reason
     * documented on {@see self::languageModelFacetCounts()}.
     *
     * @return array<string, int> ordered by count DESC then value ASC; key is the framework string
     *
     * @throws \Doctrine\DBAL\Exception when the underlying connection or query execution fails
     */
    public function frameworkFacetCounts(): array
    {
        return $this->facetCounts('framework');
    }

    /**
     * Count assistants grouped by attached tag name.
     *
     * Powers the catalogue's "Tags" facet. Unlike the scalar facets this
     * joins the `assistant_tag` relation and groups on the tag name, so
     * an assistant contributes to each of its tags' buckets. Counts are
     * computed across the full catalogue (not narrowed by the active
     * filter set) for the same reason documented on
     * {@see self::languageModelFacetCounts()}. Tags carried by no
     * assistant never appear, since the inner join drops them.
     *
     * @return array<string, int> ordered by count DESC then name ASC; key is the tag name
     *
     * @throws \Doctrine\DBAL\Exception when the underlying connection or query execution fails
     */
    public function tagFacetCounts(): array
    {
        /** @var list<array{value: string, count: int|string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('t.name AS value, COUNT(DISTINCT a.id) AS count')
            ->join('a.tags', 't')
            ->groupBy('t.name')
            ->orderBy('count', 'DESC')
            ->addOrderBy('value', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['value']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Count assistants grouped by their organisation's name.
     *
     * Powers the catalogue's "Kommune" facet. Joins the to-one
     * `organization` relation and groups on the name, so the facet keys
     * are the strings the user actually recognises rather than opaque
     * ids. Counts span the full catalogue, not the active filter set,
     * for the reason documented on {@see self::languageModelFacetCounts()}.
     *
     * Assistants with no organisation are dropped by the inner join and
     * so contribute to no bucket — matching how the tag facet treats an
     * untagged assistant, and leaving "no kommune" a state you reach by
     * clearing the facet rather than by selecting a bucket.
     *
     * @return array<string, int> ordered by count DESC then name ASC; key is the organisation name
     *
     * @throws \Doctrine\DBAL\Exception when the underlying connection or query execution fails
     */
    public function organizationFacetCounts(): array
    {
        /** @var list<array{value: string, count: int|string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('o.name AS value, COUNT(DISTINCT a.id) AS count')
            ->join('a.organization', 'o')
            ->groupBy('o.name')
            ->orderBy('count', 'DESC')
            ->addOrderBy('value', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['value']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Count assistants grouped by their data-sensitivity classification.
     *
     * Powers the catalogue's "Datafølsomhed" facet. Keys are the enum's
     * backing values, so the template resolves each to a label through
     * {@see DataSensitivity::label()} rather than showing
     * `ordinary_personal` to a curator. Unclassified assistants carry
     * `null` and are skipped, since there is no bucket to put them in.
     *
     * @return array<string, int> ordered by count DESC then value ASC; key is the enum backing value
     *
     * @throws \Doctrine\DBAL\Exception when the underlying connection or query execution fails
     */
    public function dataSensitivityFacetCounts(): array
    {
        /** @var list<array{value: DataSensitivity|string|null, count: int|string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.dataSensitivity AS value, COUNT(a.id) AS count')
            ->andWhere('a.dataSensitivity IS NOT NULL')
            ->groupBy('a.dataSensitivity')
            ->orderBy('count', 'DESC')
            ->addOrderBy('value', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            // Array hydration of an `enumType` column has returned both
            // the case and its backing string across ORM versions, so
            // normalise rather than assume either.
            $value = $row['value'];
            $counts[$value instanceof DataSensitivity ? $value->value : (string) $value] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Build a value → count map for one scalar field on `Assistant`.
     *
     * Shared backbone for the facet helpers above. Groups on the named
     * field, orders the result by count DESC then value ASC, and casts
     * each row to native PHP types before returning.
     *
     * @param string $field DQL field name on the `Assistant` alias `a` (e.g. `languageModel`)
     *
     * @return array<string, int> ordered by count DESC then value ASC
     *
     * @throws \Doctrine\DBAL\Exception when the underlying connection or query execution fails
     */
    private function facetCounts(string $field): array
    {
        /** @var list<array{value: string, count: int|string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select(sprintf('a.%s AS value, COUNT(a.id) AS count', $field))
            ->groupBy('a.'.$field)
            ->orderBy('count', 'DESC')
            ->addOrderBy('value', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['value']] = (int) $row['count'];
        }

        return $counts;
    }
}
