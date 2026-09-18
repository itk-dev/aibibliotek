<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Persistence\ObjectManager;
use ITKDev\EntityBundle\Entity\Contract\BlameableInterface;

/**
 * Assign the two baseline fixture users as creators of seeded elements.
 *
 * `BlameableListener` only stamps `createdBy`/`modifiedBy` when there is an
 * authenticated security user, and fixture loading has none — so seeded rows
 * would otherwise land with null blame columns. This helper resolves alice and
 * bob (seeded by {@see UserFixtures}) as fully-loaded managed entities and
 * stamps them round-robin, so local-development data exercises the ownership
 * relation.
 *
 * Resolving by e-mail query — rather than the fixtures reference repository —
 * deliberately avoids handing out Doctrine proxies: the audit listener loads
 * the real user during flush, and a proxy for the same id would collide in the
 * identity map. When the users are absent (the unit tests pass a mock
 * ObjectManager whose repository returns null), resolution yields an empty list
 * and assignment is a no-op.
 */
final class FixtureCreators
{
    /**
     * Resolve the round-robin creators from persisted users.
     *
     * @param ObjectManager $manager object manager used to look the users up by e-mail
     *
     * @return list<User> `[alice, bob]` when both exist, otherwise an empty list
     */
    public static function resolve(ObjectManager $manager): array
    {
        $repository = $manager->getRepository(User::class);
        $alice = $repository->findOneBy(['email' => UserFixtures::ALICE_EMAIL]);
        $bob = $repository->findOneBy(['email' => UserFixtures::BOB_EMAIL]);

        return $alice instanceof User && $bob instanceof User ? [$alice, $bob] : [];
    }

    /**
     * Stamp an entity with a creating (and modifying) user.
     *
     * Alternates between the resolved creators by index so both fixture users
     * own a share of the seeded data. A no-op when no creators were resolved.
     *
     * @param list<User>         $creators round-robin creators from {@see resolve()}
     * @param BlameableInterface $entity   the entity to stamp
     * @param int                $index    running index driving the alice/bob round-robin
     */
    public static function assign(array $creators, BlameableInterface $entity, int $index): void
    {
        if ([] === $creators) {
            return;
        }

        $user = $creators[$index % \count($creators)];
        $entity->setCreatedBy($user);
        $entity->setModifiedBy($user);
    }
}
