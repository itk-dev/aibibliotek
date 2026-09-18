<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assistant;

use App\Assistant\AssistantCreator;
use App\Assistant\InvalidAssistantInputException;
use App\Entity\Tag;
use App\Enum\DataSensitivity;
use App\Repository\AssistantRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration coverage of the create-an-assistant service.
 *
 * Uses the real `OpenWebUiConfigValidator` + Doctrine entity
 * manager so the persistence round-trip and the validation
 * pipeline are exercised together.
 */
final class AssistantCreatorTest extends KernelTestCase
{
    private AssistantCreator $creator;
    private AssistantRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->creator = $container->get(AssistantCreator::class);
        $this->repository = $container->get(AssistantRepository::class);
    }

    // Tests the happy path: a valid payload persists an Assistant with the form data and a sanitised config.
    public function testCreatePersistsAssistantWithSanitisedConfig(): void
    {
        $assistant = $this->creator->create(
            'Service Test Assistant',
            'A description',
            'gpt-4o',
            'openwebui',
            ['alpha', 'beta'],
            '{"name":"demo","base_model_id":"gpt-4o"}',
        );

        self::assertNotNull($assistant->getId());
        self::assertSame('Service Test Assistant', $assistant->getTitle());
        self::assertSame(
            ['alpha', 'beta'],
            array_map(static fn (Tag $t) => $t->getName(), $assistant->getTags()->toArray()),
        );
        self::assertSame(['name' => 'demo', 'base_model_id' => 'gpt-4o'], $assistant->getSourceConfig());
    }

    // Verifies null / empty metadata inputs persist as null on the row, keeping the "curator left blank" convention explicit. The happy-path assertion (metadata fields land verbatim) is covered by the fixture baseline, so only the AssistantCreator-specific null-folding + ULID-resolution branches live here.
    public function testCreateFoldsBlankMetadataToNull(): void
    {
        $assistant = $this->creator->create(
            'Assistant with blanks',
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

        self::assertNull($assistant->getOrganization());
        self::assertNull($assistant->getTagline());
        self::assertNull($assistant->getKnowledgeDescription());
        self::assertNull($assistant->getDataSensitivity());
    }

    // Verifies a malformed organization ULID resolves to null rather than raising, so tampered POST data doesn't 500.
    public function testCreateIgnoresInvalidOrganizationUlid(): void
    {
        $assistant = $this->creator->create(
            'Assistant with bad org id',
            'A description',
            'gpt-4o',
            'openwebui',
            [],
            '{"name":"demo","base_model_id":"gpt-4o"}',
            organizationId: 'not-a-ulid',
            dataSensitivity: DataSensitivity::OrdinaryPersonal,
        );

        self::assertNull($assistant->getOrganization());
    }

    // Verifies a well-formed ULID that no organization row matches resolves to null.
    public function testCreateIgnoresUnknownOrganizationUlid(): void
    {
        $assistant = $this->creator->create(
            'Assistant with unknown org id',
            'A description',
            'gpt-4o',
            'openwebui',
            [],
            '{"name":"demo","base_model_id":"gpt-4o"}',
            organizationId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            dataSensitivity: DataSensitivity::OrdinaryPersonal,
        );

        self::assertNull($assistant->getOrganization());
    }

    // Ensures a submitted alias is folded to its canonical id before persistence, keeping the language-model facet deduplicated.
    public function testCreateNormalisesAliasToCanonicalLanguageModel(): void
    {
        $assistant = $this->creator->create(
            'Alias normalised',
            'A description',
            'openai/gpt-4o',
            'openwebui',
            [],
            '{"name":"demo","base_model_id":"gpt-4o"}',
        );

        self::assertSame('gpt-4o', $assistant->getLanguageModel());
    }

    // Verifies an unknown/free-typed language model passes through verbatim, so curator-specific values survive.
    public function testCreatePreservesUnknownLanguageModel(): void
    {
        $assistant = $this->creator->create(
            'Custom model preserved',
            'A description',
            'my-local-llm',
            'openwebui',
            [],
            '{"name":"demo","base_model_id":"gpt-4o"}',
        );

        self::assertSame('my-local-llm', $assistant->getLanguageModel());
    }

    // Verifies the real array-wrapped export is unwrapped and stripped of PII / instance data before storage.
    public function testCreateUnwrapsArrayAndStripsPiiAndInstanceData(): void
    {
        $payload = json_encode([[
            'id' => 'det-gode-stillingsopslag',
            'user_id' => 'redacted',
            'name' => 'Demo',
            'base_model_id' => 'gpt-4o',
            'params' => ['system' => 'Du er en assistent.', 'temperature' => 0.5],
            'meta' => [
                'description' => 'A helpful assistant',
                'capabilities' => ['vision' => false, 'file_upload' => true],
                'tags' => [['name' => 'alpha']],
                'knowledge' => [['id' => 'k1', 'user' => ['email' => 'a@b.dk']]],
            ],
            'access_grants' => [],
            'is_active' => false,
            'created_at' => 1749551160,
            'updated_at' => 1750149305,
            'user' => ['id' => 'redacted', 'email' => 'a@b.dk', 'role' => 'admin'],
            'write_access' => true,
        ]], \JSON_THROW_ON_ERROR);

        $assistant = $this->creator->create('Stored', 'd', 'gpt-4o', 'openwebui', [], $payload);

        $reloaded = $this->repository->find($assistant->getId());
        self::assertNotNull($reloaded);
        self::assertSame([
            'name' => 'Demo',
            'base_model_id' => 'gpt-4o',
            'params' => ['system' => 'Du er en assistent.'],
            'meta' => [
                'description' => 'A helpful assistant',
                'capabilities' => ['vision' => false, 'file_upload' => true],
                'tags' => [['name' => 'alpha']],
            ],
        ], $reloaded->getSourceConfig());
    }

    // Ensures a payload that parses but fails the schema (missing name) is rejected without persisting.
    public function testCreateRejectsSchemaInvalidPayload(): void
    {
        try {
            $this->creator->create('Schema rejected', 'd', 'm', 'openwebui', [], '{"base_model_id":"gpt-4o"}');
            self::fail('Expected InvalidAssistantInputException.');
        } catch (InvalidAssistantInputException $e) {
            self::assertNotEmpty($e->getErrors());
        }

        self::assertNull($this->repository->findOneBy(['title' => 'Schema rejected']));
    }

    // Ensures malformed JSON triggers the InvalidAssistantInputException carrying the validator errors.
    public function testCreateRejectsMalformedJsonWithErrors(): void
    {
        try {
            $this->creator->create(
                'Rejected',
                'd',
                'm',
                'openwebui',
                [],
                '{not json',
            );
            self::fail('Expected InvalidAssistantInputException.');
        } catch (InvalidAssistantInputException $e) {
            self::assertNotEmpty($e->getErrors());
        }

        self::assertNull($this->repository->findOneBy(['title' => 'Rejected']));
    }

    // Ensures an unregistered framework id fails as a friendly InvalidAssistantInputException, not a raw 500.
    public function testCreateRejectsUnknownFramework(): void
    {
        try {
            $this->creator->create('Bad framework', 'd', 'm', 'no-such-format', [], '{"name":"demo"}');
            self::fail('Expected InvalidAssistantInputException.');
        } catch (InvalidAssistantInputException $e) {
            self::assertSame(['Unknown format "no-such-format".'], $e->getErrors());
        }

        self::assertNull($this->repository->findOneBy(['title' => 'Bad framework']));
    }
}
