<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\DataFixtures\UserFixtures;
use App\Entity\Tag;
use App\Repository\AssistantRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end coverage of the three-step assistant-create wizard.
 *
 * The wizard uses Symfony's `AbstractFlowType` with
 * `SessionDataStorage`, so tests thread session cookies via
 * `KernelBrowser` across the step boundaries. Each test either
 * exercises one step or walks all three (step 1 → step 2 →
 * receipt) as a full happy-path.
 */
final class AssistantCreateControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The create form is gated behind authentication (any
        // logged-in user; no role required), so log in the
        // fixture baseline user before each test.
        $alice = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => UserFixtures::ALICE_EMAIL]);
        \assert(null !== $alice, 'UserFixtures must seed alice@example.test.');
        $this->client->loginUser($alice);
    }

    // Tests that GET /assistant/new renders step 1 with the JSON textarea + file input, plus the step rail.
    public function testFormRendersStepOne(): void
    {
        $crawler = $this->client->request('GET', '/assistant/new');

        self::assertResponseIsSuccessful();
        // Step 1 body: file input + a JSON textarea, no metadata fields yet.
        self::assertSelectorExists('input[type="file"]');
        self::assertSelectorExists('textarea[name$="[sourceConfig]"]');
        self::assertSelectorNotExists('input[name$="[title]"]');
        // Step rail shows all three step labels.
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Indsæt konfiguration', $body);
        self::assertStringContainsString('Gennemgang', $body);
        self::assertStringContainsString('Kvittering', $body);
    }

    // Verifies the AJAX validation endpoint returns valid=true for the syntax check on parseable JSON.
    public function testValidateConfigEndpointAcceptsValidJsonForSyntaxCheck(): void
    {
        $this->client->request(
            'POST',
            '/assistant/new/validate-config',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['json' => '{"name":"demo"}', 'check' => 'syntax'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), associative: true);
        self::assertIsArray($payload);
        self::assertTrue($payload['valid']);
        self::assertSame([], $payload['errors']);
    }

    // Ensures the AJAX validation endpoint returns valid=false and an error list for the syntax check on malformed JSON.
    public function testValidateConfigEndpointRejectsMalformedJsonForSyntaxCheck(): void
    {
        $this->client->request(
            'POST',
            '/assistant/new/validate-config',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['json' => '{not json', 'check' => 'syntax'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), associative: true);
        self::assertIsArray($payload);
        self::assertFalse($payload['valid']);
        self::assertNotEmpty($payload['errors']);
    }

    // Ensures the AJAX validation endpoint returns 400 for an unknown check identifier.
    public function testValidateConfigEndpointRejectsUnknownCheck(): void
    {
        $this->client->request(
            'POST',
            '/assistant/new/validate-config',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['json' => '{}', 'check' => 'no-such-check'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), associative: true);
        self::assertIsArray($payload);
        self::assertFalse($payload['valid']);
        self::assertNotEmpty($payload['errors']);
    }

    // Verifies a check the detected format does not define (schema on an Ollama Modelfile) counts as passed.
    public function testValidateConfigEndpointPassesInapplicableCheckForDetectedFormat(): void
    {
        $this->client->request(
            'POST',
            '/assistant/new/validate-config',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['json' => "FROM llama3.2\nSYSTEM be nice", 'check' => 'schema'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), associative: true);
        self::assertIsArray($payload);
        self::assertTrue($payload['valid']);
        self::assertSame([], $payload['errors']);
    }

    // Verifies clicking "Ret konfiguration" (Previous on step 2) preserves the curator's step-2 edits — no accidental metadata reset on the way back to step 1.
    public function testPreviousPreservesStepTwoEdits(): void
    {
        $crawler = $this->client->request('GET', '/assistant/new');
        $stepOne = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $textareaName = $this->findFieldName($stepOne->all(), '[sourceConfig]');
        $stepOne[$textareaName] = json_encode([
            'name' => 'Original title',
            'base_model_id' => 'gpt-4o',
            'meta' => ['description' => 'Original description'],
        ], \JSON_THROW_ON_ERROR);
        $crawler = $this->client->submit($stepOne);

        // Step 2: rewrite the title, then click Previous. Selecting
        // the Previous button as the form's submit binds
        // `assistant_create_flow[navigator][previous]` in the submission
        // so the flow routes through the "move back" handler.
        $stepTwoPrevious = $crawler->selectButton('assistant_create_flow[navigator][previous]')->form();
        $titleField = $this->findFieldName($stepTwoPrevious->all(), '[title]');
        $stepTwoPrevious[$titleField] = 'Curator edit';
        $crawler = $this->client->submit($stepTwoPrevious);

        // Landed on step 1 again; go forward without changing the JSON.
        $stepOneAgain = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $crawler = $this->client->submit($stepOneAgain);

        // The curator's edited title survives the round-trip — Previous
        // preserved the submitted step-2 data on the way back.
        $stepTwoAgain = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        self::assertSame('Curator edit', $stepTwoAgain[$titleField]->getValue());
    }

    // Verifies going back to step 1 and pasting a different JSON refreshes the metadata fields on step 2 that the curator hadn't manually edited.
    public function testReuploadingDifferentJsonRefreshesUntouchedMetadata(): void
    {
        $crawler = $this->client->request('GET', '/assistant/new');
        $stepOne = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $sourceField = $this->findFieldName($stepOne->all(), '[sourceConfig]');
        $stepOne[$sourceField] = json_encode([
            'name' => 'Original title',
            'base_model_id' => 'gpt-4o',
            'meta' => ['description' => 'Original description'],
        ], \JSON_THROW_ON_ERROR);
        $crawler = $this->client->submit($stepOne);

        // Go back to step 1 via the Previous button on step 2.
        $stepTwoBack = $crawler->selectButton('assistant_create_flow[navigator][previous]')->form();
        $crawler = $this->client->submit($stepTwoBack);

        // Paste a different JSON and continue.
        $stepOneAgain = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $sourceField = $this->findFieldName($stepOneAgain->all(), '[sourceConfig]');
        $stepOneAgain[$sourceField] = json_encode([
            'name' => 'Second title',
            'base_model_id' => 'gpt-4o-mini',
            'meta' => ['description' => 'Second description'],
        ], \JSON_THROW_ON_ERROR);
        $crawler = $this->client->submit($stepOneAgain);

        // Step 2 renders with the SECOND JSON's metadata — the curator
        // didn't edit any fields, so the prefiller refreshes them.
        $stepTwo = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $titleField = $this->findFieldName($stepTwo->all(), '[title]');
        self::assertSame('Second title', $stepTwo[$titleField]->getValue());
        $descriptionField = $this->findFieldName($stepTwo->all(), '[description]');
        self::assertSame('Second description', $stepTwo[$descriptionField]->getValue());
    }

    // Full happy-path: valid JSON on step 1 → auto-extracted metadata on step 2 → persist → step 3 receipt with permalink.
    public function testHappyPathAcrossThreeSteps(): void
    {
        // Step 1: paste a JSON payload with fields the extractor knows how to map.
        $crawler = $this->client->request('GET', '/assistant/new');
        $stepOne = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $textareaName = $this->findFieldName($stepOne->all(), '[sourceConfig]');
        $stepOne[$textareaName] = json_encode([
            'name' => 'Demo assistant',
            'base_model_id' => 'gpt-4o',
            'meta' => [
                'description' => 'A demo assistant',
                'tags' => ['alpha', 'beta'],
            ],
        ], \JSON_THROW_ON_ERROR);
        $crawler = $this->client->submit($stepOne);

        // Landed on step 2 with metadata pre-filled from the JSON.
        self::assertResponseIsSuccessful();
        $stepTwo = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $titleField = $this->findFieldName($stepTwo->all(), '[title]');
        self::assertSame('Demo assistant', $stepTwo[$titleField]->getValue());

        $descriptionField = $this->findFieldName($stepTwo->all(), '[description]');
        self::assertSame('A demo assistant', $stepTwo[$descriptionField]->getValue());

        $languageModelField = $this->findFieldName($stepTwo->all(), '[languageModel]');
        self::assertSame('gpt-4o', $stepTwo[$languageModelField]->getValue());

        $tagsField = $this->findFieldName($stepTwo->all(), '[tags]');
        self::assertSame('alpha, beta', $stepTwo[$tagsField]->getValue());

        // `tagline` and `dataSensitivity` are both required —
        // supply a value before advancing.
        $taglineField = $this->findFieldName($stepTwo->all(), '[tagline]');
        $stepTwo[$taglineField] = 'Kort demo-tagline';
        $sensitivityField = $this->findFieldName($stepTwo->all(), '[dataSensitivity]');
        $stepTwo[$sensitivityField] = 'ordinary_personal';

        // Advance to step 3 with the pre-filled values.
        $crawler = $this->client->submit($stepTwo);

        // Step 3 shows the "assistant delt" receipt with the persisted permalink.
        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Assistenten er delt', $body);

        $repository = self::getContainer()->get(AssistantRepository::class);
        $created = $repository->findOneBy(['title' => 'Demo assistant']);
        self::assertNotNull($created);
        self::assertSame(
            ['alpha', 'beta'],
            array_map(static fn (Tag $t) => $t->getName(), $created->getTags()->toArray()),
        );
        self::assertSame(
            ['name' => 'Demo assistant', 'base_model_id' => 'gpt-4o', 'meta' => ['description' => 'A demo assistant', 'tags' => ['alpha', 'beta']]],
            $created->getSourceConfig(),
        );

        // The permalink to the created row is on the receipt page.
        self::assertStringContainsString('/assistant/'.(string) $created->getId(), $body);
    }

    // Verifies importing a non-OpenWebUI (Ollama) config detects the format, normalises the model, and flags step 2 as experimental.
    public function testExperimentalFormatImportShowsNoticeOnStepTwo(): void
    {
        $crawler = $this->client->request('GET', '/assistant/new');
        $stepOne = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $textareaName = $this->findFieldName($stepOne->all(), '[sourceConfig]');
        $stepOne[$textareaName] = "FROM llama3.2\nSYSTEM \"\"\"Du er en hjælpsom assistent.\"\"\"";
        $crawler = $this->client->submit($stepOne);

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        // The Ollama format is experimental, so step 2 carries the caution.
        self::assertStringContainsString('eksperimentelt format', $body);

        // The detected model is folded onto its canonical id for the selector.
        $stepTwo = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $languageModelField = $this->findFieldName($stepTwo->all(), '[languageModel]');
        self::assertSame('llama-3.2', $stepTwo[$languageModelField]->getValue());
    }

    // Ensures a fresh GET after completing the wizard drops the receipt-state session slot and re-renders step 1.
    public function testGetAfterCompletionResetsToStepOne(): void
    {
        // Walk to the receipt (step 3) once.
        $crawler = $this->client->request('GET', '/assistant/new');
        $stepOne = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $textareaName = $this->findFieldName($stepOne->all(), '[sourceConfig]');
        $stepOne[$textareaName] = json_encode([
            'name' => 'Reset assistant',
            'base_model_id' => 'gpt-4o',
            'meta' => ['description' => 'Reset demo', 'tags' => ['x']],
        ], \JSON_THROW_ON_ERROR);
        $crawler = $this->client->submit($stepOne);
        $stepTwo = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $taglineField = $this->findFieldName($stepTwo->all(), '[tagline]');
        $stepTwo[$taglineField] = 'Reset demo tagline';
        $sensitivityField = $this->findFieldName($stepTwo->all(), '[dataSensitivity]');
        $stepTwo[$sensitivityField] = 'ordinary_personal';
        $this->client->submit($stepTwo);

        // Same session, fresh GET — should land on step 1 again.
        $crawler = $this->client->request('GET', '/assistant/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('textarea[name$="[sourceConfig]"]');
        self::assertSelectorNotExists('input[name$="[title]"]');
        $body = $crawler->filter('body')->text();
        self::assertStringNotContainsString('Assistenten er delt', $body);
    }

    // Ensures a step 1 submit with malformed JSON returns 422 and does not persist an Assistant.
    public function testStepOneRejectsInvalidJson(): void
    {
        $crawler = $this->client->request('GET', '/assistant/new');
        $stepOne = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $textareaName = $this->findFieldName($stepOne->all(), '[sourceConfig]');
        $stepOne[$textareaName] = '{not json';
        $this->client->submit($stepOne);

        self::assertResponseStatusCodeSame(422);

        $repository = self::getContainer()->get(AssistantRepository::class);
        self::assertNull($repository->findOneBy(['title' => '{not json']));
    }

    // Ensures step 1 rejects syntactically valid JSON that fails the model schema (a two-model array), and persists nothing.
    public function testStepOneRejectsSchemaInvalidJson(): void
    {
        $crawler = $this->client->request('GET', '/assistant/new');
        $stepOne = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $textareaName = $this->findFieldName($stepOne->all(), '[sourceConfig]');
        $stepOne[$textareaName] = json_encode([
            ['name' => 'First model'],
            ['name' => 'Second model'],
        ], \JSON_THROW_ON_ERROR);
        $this->client->submit($stepOne);

        self::assertResponseStatusCodeSame(422);

        $repository = self::getContainer()->get(AssistantRepository::class);
        self::assertNull($repository->findOneBy(['title' => 'First model']));
    }

    // Verifies the AJAX validation endpoint runs the schema check and rejects a payload missing the required name.
    public function testValidateConfigEndpointRejectsSchemaInvalidForSchemaCheck(): void
    {
        $this->client->request(
            'POST',
            '/assistant/new/validate-config',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['json' => '{"base_model_id":"gpt-4o"}', 'check' => 'schema'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), associative: true);
        self::assertIsArray($payload);
        self::assertFalse($payload['valid']);
        self::assertNotEmpty($payload['errors']);
    }

    // Ensures an invalid CSRF token yields 422 (Symfony Form rejects the submission before it reaches the flow's advance logic) and does not persist an Assistant.
    public function testStepOneRejectsInvalidCsrfToken(): void
    {
        // Prime the session with a GET so the token would otherwise be valid.
        $this->client->request('GET', '/assistant/new');

        $this->client->request('POST', '/assistant/new', [
            'assistant_create_flow' => [
                'json' => ['sourceConfig' => '{"name":"demo"}'],
                'navigator' => ['next' => ''],
                '_token' => 'nope',
            ],
        ]);

        // Symfony's form CSRF rejection surfaces as an invalid form
        // (unprocessable entity), not a raw 403.
        self::assertResponseStatusCodeSame(422);

        $repository = self::getContainer()->get(AssistantRepository::class);
        self::assertNull($repository->findOneBy(['title' => 'Hacker assistant']));
    }

    /**
     * Locate a field whose full name ends with the supplied suffix.
     * The FormFlow's dotted / bracketed field names are stable but
     * long — this helper avoids hard-coding them and re-computing
     * the block prefix in every test.
     *
     * @param array<string, \Symfony\Component\DomCrawler\Field\FormField> $fields
     */
    private function findFieldName(array $fields, string $suffix): string
    {
        foreach (array_keys($fields) as $name) {
            if (str_ends_with($name, $suffix)) {
                return $name;
            }
        }

        self::fail(\sprintf('Form field ending with "%s" not found. Available: %s', $suffix, implode(', ', array_keys($fields))));
    }
}
