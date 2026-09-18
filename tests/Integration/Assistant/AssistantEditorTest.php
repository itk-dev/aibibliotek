<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assistant;

use App\Assistant\AssistantEditor;
use App\Assistant\InvalidAssistantInputException;
use App\Entity\Assistant;
use App\Entity\Tag;
use App\Enum\DataSensitivity;
use App\Repository\AssistantRepository;
use App\Repository\OrganizationRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration coverage of the edit-an-assistant service.
 *
 * Uses the fixture-seeded baseline (`Borgerservice-vejviser`) so
 * every test starts from a realistic persisted row and applies
 * `AssistantEditor::update()` to it. The DAMA transaction rolls
 * the mutations back at tearDown.
 */
final class AssistantEditorTest extends KernelTestCase
{
    private AssistantEditor $editor;
    private AssistantRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->editor = $container->get(AssistantEditor::class);
        $this->repository = $container->get(AssistantRepository::class);
    }

    /**
     * Load the fixture assistant the edit tests mutate.
     *
     * @return Assistant the freshly-loaded baseline row
     */
    private function baseline(): Assistant
    {
        $assistant = $this->repository->findOneBy(['title' => 'Borgerservice-vejviser']);
        self::assertNotNull($assistant, 'fixture baseline must include Borgerservice-vejviser');

        return $assistant;
    }

    // Tests the happy path: submitted values overwrite the entity's columns and the tag collection reconciles.
    public function testUpdateAppliesEveryEditableField(): void
    {
        $assistant = $this->baseline();
        $organizations = self::getContainer()->get(OrganizationRepository::class);
        $odense = $organizations->findOneBy(['name' => 'Odense Kommune']);
        self::assertNotNull($odense);

        $updated = $this->editor->update(
            $assistant,
            'Edited title',
            'Edited description',
            'gpt-4o-mini',
            'openwebui',
            ['alpha', 'beta'],
            '{"name":"demo","base_model_id":"gpt-4o-mini"}',
            organizationId: (string) $odense->getId(),
            tagline: 'Kort tagline',
            knowledgeDescription: 'Ny videns-beskrivelse.',
            dataSensitivity: DataSensitivity::Confidential,
        );

        self::assertSame($assistant, $updated, 'update() mutates the entity in place');
        self::assertSame('Edited title', $updated->getTitle());
        self::assertSame('Edited description', $updated->getDescription());
        self::assertSame('gpt-4o-mini', $updated->getLanguageModel());
        self::assertSame($odense->getId(), $updated->getOrganization()?->getId());
        self::assertSame('Kort tagline', $updated->getTagline());
        self::assertSame('Ny videns-beskrivelse.', $updated->getKnowledgeDescription());
        self::assertSame(DataSensitivity::Confidential, $updated->getDataSensitivity());
        self::assertSame(
            ['alpha', 'beta'],
            array_values(array_map(static fn (Tag $t) => $t->getName(), $updated->getTags()->toArray())),
        );
        self::assertSame(['name' => 'demo', 'base_model_id' => 'gpt-4o-mini'], $updated->getSourceConfig());
    }

    // Verifies blank / empty inputs on the nullable columns persist as null, keeping the "curator cleared the field" convention explicit.
    public function testUpdateFoldsBlankMetadataToNull(): void
    {
        $updated = $this->editor->update(
            $this->baseline(),
            'Cleared metadata',
            'A description',
            'gpt-4o',
            'openwebui',
            [],
            '{"name":"demo","base_model_id":"gpt-4o"}',
            organizationId: null,
            tagline: '   ',
            knowledgeDescription: '',
            dataSensitivity: null,
        );

        self::assertNull($updated->getOrganization());
        self::assertNull($updated->getTagline());
        self::assertNull($updated->getKnowledgeDescription());
        self::assertNull($updated->getDataSensitivity());
    }

    // Verifies a malformed organization ULID resolves to null rather than raising, so tampered POST data doesn't 500.
    public function testUpdateIgnoresInvalidOrganizationUlid(): void
    {
        $updated = $this->editor->update(
            $this->baseline(),
            'Bad org id',
            'A description',
            'gpt-4o',
            'openwebui',
            [],
            '{"name":"demo","base_model_id":"gpt-4o"}',
            organizationId: 'not-a-ulid',
            dataSensitivity: DataSensitivity::OrdinaryPersonal,
        );

        self::assertNull($updated->getOrganization());
    }

    // Verifies a well-formed ULID that no organization row matches resolves to null.
    public function testUpdateIgnoresUnknownOrganizationUlid(): void
    {
        $updated = $this->editor->update(
            $this->baseline(),
            'Unknown org id',
            'A description',
            'gpt-4o',
            'openwebui',
            [],
            '{"name":"demo","base_model_id":"gpt-4o"}',
            organizationId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            dataSensitivity: DataSensitivity::OrdinaryPersonal,
        );

        self::assertNull($updated->getOrganization());
    }

    // Ensures a submitted alias is folded to the canonical id on update — same rule the create path applies.
    public function testUpdateNormalisesAliasToCanonicalLanguageModel(): void
    {
        $updated = $this->editor->update(
            $this->baseline(),
            'Alias normalised',
            'A description',
            'openai/gpt-4o',
            'openwebui',
            [],
            '{"name":"demo","base_model_id":"gpt-4o"}',
        );

        self::assertSame('gpt-4o', $updated->getLanguageModel());
    }

    // Ensures an unregistered framework id fails as a friendly InvalidAssistantInputException, not a raw 500.
    public function testUpdateRejectsUnknownFramework(): void
    {
        $this->expectException(InvalidAssistantInputException::class);

        $this->editor->update(
            $this->baseline(),
            'Bad framework',
            'A description',
            'gpt-4o',
            'no-such-format',
            [],
            '{"name":"demo"}',
        );
    }
}
