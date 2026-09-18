<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataFixtures;

use App\DataFixtures\AssistantFixtures;
use App\DataFixtures\OrganizationFixtures;
use App\DataFixtures\UserFixtures;
use App\Entity\Assistant;
use App\Entity\Organization;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

final class AssistantFixturesTest extends TestCase
{
    // Ensures the fixture declares UserFixtures + OrganizationFixtures as dependencies so both are loaded first.
    public function testDependsOnUserFixturesAndOrganizations(): void
    {
        self::assertSame(
            [UserFixtures::class, OrganizationFixtures::class],
            (new AssistantFixtures())->getDependencies(),
        );
    }

    // Ensures the fixture is grouped under `default` so the stg pipeline can load it with `--group=default`.
    public function testBelongsToDefaultGroup(): void
    {
        self::assertSame(['default'], AssistantFixtures::getGroups());
    }

    // Tests that load() persists 21 entries — six detailed (including a tagless edge-case) and fifteen generated — with unique signatures and the expected language-model rotation.
    public function testLoadPersistsSixDetailedAndFifteenGenerated(): void
    {
        $persisted = $this->captureLoad();

        self::assertCount(21, $persisted);

        $detailedTitles = array_map(
            static fn (Assistant $a) => $a->getTitle(),
            \array_slice($persisted, 0, 6),
        );
        self::assertSame(
            [
                'Borgerservice-vejviser',
                'Mødereferent',
                'Journaliseringsassistent',
                'Skole- og dagtilbudssvar',
                'Tilsynsrapport-assistent',
                'Uden kategorier',
            ],
            $detailedTitles,
        );
        self::assertCount(0, $persisted[5]->getTags(), 'tagless detailed entry must carry no tags');

        $generated = \array_slice($persisted, 6);
        self::assertCount(15, $generated);
        foreach ($generated as $assistant) {
            self::assertStringContainsString(' – ', $assistant->getTitle(), 'generated titles include the kommune');
            self::assertStringContainsString('Delt af ', $assistant->getDescription());
        }

        $signatures = array_map(
            static fn (Assistant $a) => $a->getTitle().'|'.$a->getDescription(),
            $generated,
        );
        self::assertSame($signatures, array_unique($signatures), 'every generated entry must be unique');

        $models = array_unique(array_map(
            static fn (Assistant $a) => $a->getLanguageModel(),
            $generated,
        ));
        sort($models);
        self::assertSame(
            ['gpt-4o', 'gpt-4o-mini', 'llama-3.1', 'llama-3.2', 'mistral', 'o3-mini'],
            $models,
        );
    }

    // Ensures two consecutive load() invocations yield the same title sequence (no randomness in the fixture generator).
    public function testLoadIsDeterministic(): void
    {
        $first = array_map(
            static fn (Assistant $a) => $a->getTitle(),
            $this->captureLoad(),
        );
        $second = array_map(
            static fn (Assistant $a) => $a->getTitle(),
            $this->captureLoad(),
        );

        self::assertSame($first, $second);
    }

    /**
     * @param list<Organization> $organizations organisations the mocked repository should return
     *
     * @return list<Assistant>
     */
    private function captureLoad(array $organizations = []): array
    {
        $captured = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(function (object $entity) use (&$captured): void {
            \assert($entity instanceof Assistant);
            $captured[] = $entity;
        });
        $manager->expects(self::once())->method('flush');

        $repository = $this->createStub(ObjectRepository::class);
        $repository->method('findAll')->willReturn($organizations);
        $manager->method('getRepository')->willReturn($repository);

        (new AssistantFixtures())->load($manager);

        return $captured;
    }

    // Verifies the detailed batch attaches the sharing organization when one matches the row's kommune by name.
    public function testDetailedEntriesAttachOrganizationByName(): void
    {
        $aarhus = new Organization('Aarhus Kommune', ['aarhus.dk'], 'openwebui');
        $aalborg = new Organization('Aalborg Kommune', ['aalborg.dk'], 'openwebui');
        $odense = new Organization('Odense Kommune', ['odense.dk'], 'openwebui');

        $persisted = $this->captureLoad([$aarhus, $aalborg, $odense]);

        self::assertSame($aarhus, $persisted[0]->getOrganization(), 'Borgerservice-vejviser → Aarhus');
        self::assertSame($odense, $persisted[2]->getOrganization(), 'Journaliseringsassistent → Odense');
        self::assertSame($aalborg, $persisted[4]->getOrganization(), 'Tilsynsrapport-assistent → Aalborg');
    }
}
