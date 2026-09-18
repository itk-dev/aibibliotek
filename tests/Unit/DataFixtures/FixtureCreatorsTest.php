<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataFixtures;

use App\DataFixtures\FixtureCreators;
use App\DataFixtures\UserFixtures;
use App\Entity\Assistant;
use App\Entity\User;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

final class FixtureCreatorsTest extends TestCase
{
    // Tests that resolve() returns the two baseline users in round-robin order when both are persisted.
    public function testResolveReturnsBothUsersWhenPresent(): void
    {
        $alice = new User();
        $bob = new User();

        $creators = FixtureCreators::resolve($this->managerReturning($alice, $bob));

        self::assertSame([$alice, $bob], $creators);
    }

    // Tests that resolve() yields an empty list when a baseline user is missing (the standalone-load case).
    public function testResolveReturnsEmptyListWhenAUserIsMissing(): void
    {
        $creators = FixtureCreators::resolve($this->managerReturning(new User(), null));

        self::assertSame([], $creators);
    }

    // Tests that assign() stamps createdBy and modifiedBy round-robin by index.
    public function testAssignStampsCreatorsRoundRobin(): void
    {
        $alice = new User();
        $bob = new User();
        $creators = [$alice, $bob];

        $first = $this->assistant();
        $second = $this->assistant();

        FixtureCreators::assign($creators, $first, 0);
        FixtureCreators::assign($creators, $second, 1);

        self::assertSame($alice, $first->getCreatedBy());
        self::assertSame($alice, $first->getModifiedBy());
        self::assertSame($bob, $second->getCreatedBy());
        self::assertSame($bob, $second->getModifiedBy());
    }

    // Tests that assign() leaves the blame columns untouched when no creators were resolved.
    public function testAssignIsNoOpForEmptyCreators(): void
    {
        $assistant = $this->assistant();

        FixtureCreators::assign([], $assistant, 0);

        self::assertNull($assistant->getCreatedBy());
        self::assertNull($assistant->getModifiedBy());
    }

    /**
     * Build an ObjectManager whose user repository returns the given users by e-mail.
     *
     * @param User|null $alice user returned for {@see UserFixtures::ALICE_EMAIL}
     * @param User|null $bob   user returned for {@see UserFixtures::BOB_EMAIL}
     *
     * @return ObjectManager the configured mock manager
     */
    private function managerReturning(?User $alice, ?User $bob): ObjectManager
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?User => match ($criteria['email'] ?? null) {
                UserFixtures::ALICE_EMAIL => $alice,
                UserFixtures::BOB_EMAIL => $bob,
                default => null,
            },
        );

        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getRepository')->with(User::class)->willReturn($repository);

        return $manager;
    }

    /**
     * Build a throwaway assistant to receive the blame stamps.
     *
     * @return Assistant a persistable assistant with the blame columns still null
     */
    private function assistant(): Assistant
    {
        return new Assistant(
            title: 'Test',
            description: 'Test',
            languageModel: 'gpt-4o',
            framework: 'openwebui',
        );
    }
}
