<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assistant;

use App\Assistant\AssistantDraft;
use App\Assistant\AssistantDraftPrefiller;
use App\Assistant\Format\FormatAdapterRegistry;
use App\Assistant\Format\OpenWebUiAdapter;
use App\Assistant\Model\ModelMap;
use App\Assistant\OpenWebUiConfigSanitizer;
use App\Assistant\OpenWebUiModelNormalizer;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Validator\OpenWebUiConfigValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Unit tests for the step-2 draft pre-filler.
 */
final class AssistantDraftPrefillerTest extends TestCase
{
    private function prefiller(): AssistantDraftPrefiller
    {
        // Anonymous request stub — `getUser()` returns `null` so the
        // organization-defaulting branch is a no-op for the existing
        // format-detection assertions below. Dedicated tests cover the
        // organization branch separately.
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        return new AssistantDraftPrefiller(
            new FormatAdapterRegistry([
                new OpenWebUiAdapter(
                    new OpenWebUiConfigValidator(\dirname(__DIR__, 3).'/config/schema/openwebui-model.json'),
                    new OpenWebUiModelNormalizer(),
                    new OpenWebUiConfigSanitizer(),
                    new ModelMap(\dirname(__DIR__, 3).'/config/model_map.yaml'),
                ),
            ]),
            new ModelMap(\dirname(__DIR__, 3).'/config/model_map.yaml'),
            $this->createStub(OrganizationRepository::class),
            $security,
        );
    }

    // Verifies an empty draft is filled from the detected format and records the format id.
    public function testPrefillsEmptyDraftAndSetsFramework(): void
    {
        $draft = new AssistantDraft();
        $draft->sourceConfig = json_encode([
            'name' => 'Demo assistant',
            'base_model_id' => 'gpt-4o',
            'meta' => ['description' => 'A demo assistant', 'tags' => ['alpha', 'beta']],
        ], \JSON_THROW_ON_ERROR);

        $this->prefiller()->prefill($draft);

        self::assertSame('openwebui', $draft->framework);
        self::assertSame('Demo assistant', $draft->title);
        self::assertSame('A demo assistant', $draft->description);
        self::assertSame('gpt-4o', $draft->languageModel);
        self::assertSame(['alpha', 'beta'], $draft->tags);
    }

    // Verifies description falls back to the system prompt when the source has no meta.description.
    public function testDescriptionFallsBackToSystemPrompt(): void
    {
        $draft = new AssistantDraft();
        $draft->sourceConfig = json_encode([
            'name' => 'Demo',
            'params' => ['system' => 'You are helpful.'],
        ], \JSON_THROW_ON_ERROR);

        $this->prefiller()->prefill($draft);

        self::assertSame('You are helpful.', $draft->description);
    }

    // Verifies language model falls back to the legacy `model` key.
    public function testLanguageModelFallsBackToModelKey(): void
    {
        $draft = new AssistantDraft();
        $draft->sourceConfig = json_encode(['name' => 'Demo', 'model' => 'llama3.1:70b'], \JSON_THROW_ON_ERROR);

        $this->prefiller()->prefill($draft);

        self::assertSame('llama3.1:70b', $draft->languageModel);
    }

    // Verifies the detected model is folded onto its canonical id for the selector default.
    public function testLanguageModelIsNormalisedToCanonicalId(): void
    {
        $draft = new AssistantDraft();
        $draft->sourceConfig = json_encode(['name' => 'Demo', 'base_model_id' => 'llama3.2:latest'], \JSON_THROW_ON_ERROR);

        $this->prefiller()->prefill($draft);

        self::assertSame('llama-3.2', $draft->languageModel);
    }

    // Verifies re-uploading a different JSON refreshes the derived fields the curator hasn't edited, and preserves the ones they have.
    public function testRefreshesUntouchedFieldsButPreservesManualEdits(): void
    {
        $draft = new AssistantDraft();
        $draft->sourceConfig = json_encode([
            'name' => 'Original title',
            'base_model_id' => 'gpt-4o',
            'meta' => ['description' => 'Original description', 'tags' => ['alpha']],
        ], \JSON_THROW_ON_ERROR);

        // First upload: baseline is recorded, empty draft fields fill.
        $this->prefiller()->prefill($draft);
        self::assertSame('Original title', $draft->title);
        self::assertSame('Original description', $draft->description);

        // Curator edits the title on step 2; description stays as-is.
        $draft->title = 'Curator edit';

        // Curator goes back to step 1 and pastes a different JSON.
        $draft->sourceConfig = json_encode([
            'name' => 'Second upload',
            'base_model_id' => 'gpt-4o-mini',
            'meta' => ['description' => 'Second description', 'tags' => ['beta']],
        ], \JSON_THROW_ON_ERROR);
        $this->prefiller()->prefill($draft);

        // Title stays because the curator edited it — its current value
        // differs from the recorded baseline.
        self::assertSame('Curator edit', $draft->title);
        // Description / language model / tags refresh because their
        // current values still matched the previous baseline.
        self::assertSame('Second description', $draft->description);
        self::assertSame('gpt-4o-mini', $draft->languageModel);
        self::assertSame(['beta'], $draft->tags);
        // The baseline snapshot now reflects the second upload — so
        // subsequent "(Ændret)" comparisons on step 2 compare against
        // the fresh JSON, not the original one.
        self::assertSame('Second upload', $draft->jsonBaseline['title']);
    }

    // Verifies pre-set fields survive a re-run so Back→edit→Next doesn't clobber user input.
    public function testDoesNotOverwriteExistingFields(): void
    {
        $draft = new AssistantDraft();
        $draft->title = 'User title';
        $draft->description = 'User description';
        $draft->languageModel = 'user-model';
        $draft->tags = ['user-tag'];
        $draft->sourceConfig = json_encode([
            'name' => 'From JSON',
            'base_model_id' => 'gpt-4o',
            'meta' => ['description' => 'From JSON', 'tags' => ['alpha']],
        ], \JSON_THROW_ON_ERROR);

        $this->prefiller()->prefill($draft);

        self::assertSame('User title', $draft->title);
        self::assertSame('User description', $draft->description);
        self::assertSame('user-model', $draft->languageModel);
        self::assertSame(['user-tag'], $draft->tags);
    }

    // Verifies an unrecognised payload leaves the draft untouched.
    public function testUnrecognisedConfigLeavesDraftUntouched(): void
    {
        $draft = new AssistantDraft();
        $draft->sourceConfig = '{not json';

        $this->prefiller()->prefill($draft);

        self::assertSame('', $draft->title);
        self::assertSame('', $draft->description);
        self::assertSame('', $draft->languageModel);
        self::assertSame([], $draft->tags);
    }

    // Verifies the draft's organization defaults from the logged-in user's e-mail domain via the repository lookup.
    public function testOrganizationDefaultsFromActingUsersEmailDomain(): void
    {
        $user = new User();
        $user->setEmail('curator@aarhus.dk');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $organization = new Organization(
            name: 'Aarhus Kommune',
            emailDomains: ['aarhus.dk'],
            defaultFramework: 'openwebui',
        );

        $organizations = $this->createStub(OrganizationRepository::class);
        $organizations->method('findOneByEmailDomain')->with('aarhus.dk')->willReturn($organization);

        $prefiller = new AssistantDraftPrefiller(
            new FormatAdapterRegistry([
                new OpenWebUiAdapter(
                    new OpenWebUiConfigValidator(\dirname(__DIR__, 3).'/config/schema/openwebui-model.json'),
                    new OpenWebUiModelNormalizer(),
                    new OpenWebUiConfigSanitizer(),
                    new ModelMap(\dirname(__DIR__, 3).'/config/model_map.yaml'),
                ),
            ]),
            new ModelMap(\dirname(__DIR__, 3).'/config/model_map.yaml'),
            $organizations,
            $security,
        );

        $draft = new AssistantDraft();
        $draft->sourceConfig = json_encode(['name' => 'Demo', 'base_model_id' => 'gpt-4o'], \JSON_THROW_ON_ERROR);

        $prefiller->prefill($draft);

        self::assertSame((string) $organization->getId(), $draft->organizationId);
    }

    // Verifies a pre-set organizationId survives a re-run.
    public function testExistingOrganizationIdIsNotOverwritten(): void
    {
        $user = new User();
        $user->setEmail('curator@aarhus.dk');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $organizations = $this->createMock(OrganizationRepository::class);
        $organizations->expects(self::never())->method('findOneByEmailDomain');

        $prefiller = new AssistantDraftPrefiller(
            new FormatAdapterRegistry([
                new OpenWebUiAdapter(
                    new OpenWebUiConfigValidator(\dirname(__DIR__, 3).'/config/schema/openwebui-model.json'),
                    new OpenWebUiModelNormalizer(),
                    new OpenWebUiConfigSanitizer(),
                    new ModelMap(\dirname(__DIR__, 3).'/config/model_map.yaml'),
                ),
            ]),
            new ModelMap(\dirname(__DIR__, 3).'/config/model_map.yaml'),
            $organizations,
            $security,
        );

        $draft = new AssistantDraft();
        $draft->organizationId = 'pre-set-id';
        $draft->sourceConfig = json_encode(['name' => 'Demo', 'base_model_id' => 'gpt-4o'], \JSON_THROW_ON_ERROR);

        $prefiller->prefill($draft);

        self::assertSame('pre-set-id', $draft->organizationId);
    }

    // Verifies a user whose e-mail carries no `@` — malformed or unset — resolves the organization to null.
    public function testMalformedEmailLeavesOrganizationUnset(): void
    {
        $user = new User();
        $user->setEmail('no-at-sign');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $organizations = $this->createMock(OrganizationRepository::class);
        $organizations->expects(self::never())->method('findOneByEmailDomain');

        $prefiller = new AssistantDraftPrefiller(
            new FormatAdapterRegistry([
                new OpenWebUiAdapter(
                    new OpenWebUiConfigValidator(\dirname(__DIR__, 3).'/config/schema/openwebui-model.json'),
                    new OpenWebUiModelNormalizer(),
                    new OpenWebUiConfigSanitizer(),
                    new ModelMap(\dirname(__DIR__, 3).'/config/model_map.yaml'),
                ),
            ]),
            new ModelMap(\dirname(__DIR__, 3).'/config/model_map.yaml'),
            $organizations,
            $security,
        );

        $draft = new AssistantDraft();
        $draft->sourceConfig = json_encode(['name' => 'Demo', 'base_model_id' => 'gpt-4o'], \JSON_THROW_ON_ERROR);

        $prefiller->prefill($draft);

        self::assertNull($draft->organizationId);
    }
}
