<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataFixtures;

use App\DataFixtures\OrganizationFixtures;
use App\DataFixtures\UserFixtures;
use App\Entity\Organization;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class OrganizationFixturesTest extends TestCase
{
    // Ensures the fixture declares UserFixtures as a dependency so users load — and creators resolve — first.
    public function testDependsOnUserFixtures(): void
    {
        self::assertSame([UserFixtures::class], (new OrganizationFixtures())->getDependencies());
    }

    // Ensures the fixture is grouped under `default` so the stg pipeline can load it with `--group=default`.
    public function testBelongsToDefaultGroup(): void
    {
        self::assertSame(['default'], OrganizationFixtures::getGroups());
    }

    // Tests that load() persists the four baseline organisations (three Danish municipalities + the test-friendly Eksempel row) with the expected names and default framework.
    public function testLoadPersistsBaselineOrganizations(): void
    {
        $persisted = $this->captureLoad();

        self::assertCount(4, $persisted);

        $names = array_map(
            static fn (Organization $o) => $o->getName(),
            $persisted,
        );
        self::assertSame(
            ['Aarhus Kommune', 'Aalborg Kommune', 'Odense Kommune', 'Eksempel Kommune'],
            $names,
        );

        foreach ($persisted as $organization) {
            self::assertSame('openwebui', $organization->getDefaultFramework());
            self::assertNotEmpty($organization->getEmailDomains(), 'every fixture organization has at least one email domain');
        }

        // The Eksempel row owns the `example.test` domain so the
        // integration suite + fixture users continue to register
        // successfully once `AllowedEmailDomains` reads the DB.
        $eksempel = $persisted[3];
        self::assertContains('example.test', $eksempel->getEmailDomains());
    }

    // Ensures load() emits the same organizations on every run (no randomness).
    public function testLoadIsDeterministic(): void
    {
        $first = array_map(
            static fn (Organization $o) => $o->getName(),
            $this->captureLoad(),
        );
        $second = array_map(
            static fn (Organization $o) => $o->getName(),
            $this->captureLoad(),
        );

        self::assertSame($first, $second);
    }

    /**
     * @return list<Organization>
     */
    private function captureLoad(): array
    {
        $captured = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(function (object $entity) use (&$captured): void {
            \assert($entity instanceof Organization);
            $captured[] = $entity;
        });
        $manager->expects(self::once())->method('flush');

        (new OrganizationFixtures())->load($manager);

        return $captured;
    }
}
