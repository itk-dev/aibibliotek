<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /**
     * Look up a single tag by its exact name.
     *
     * Backs tag de-duplication: callers that need "the tag named X,
     * creating it once" resolve the existing row through this helper
     * before persisting a new {@see Tag}. The `name` column is unique,
     * so at most one row can match.
     *
     * @param string $name the exact tag name to look up
     *
     * @return Tag|null the matching tag, or null when no tag carries that name
     */
    public function findOneByName(string $name): ?Tag
    {
        return $this->findOneBy(['name' => $name]);
    }
}
