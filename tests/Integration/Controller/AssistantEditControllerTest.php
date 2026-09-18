<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\DataFixtures\UserFixtures;
use App\Entity\Assistant;
use App\Entity\User;
use App\Repository\AssistantRepository;
use App\Repository\UserRepository;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end coverage of the three-step assistant-edit wizard.
 *
 * The wizard shares its flow type + step partials with the create
 * wizard; the edit controller seeds a per-assistant session slot
 * from the persisted entity and routes the `metadata → receipt`
 * transition through {@see \App\Assistant\AssistantEditor} so the
 * row is updated in place rather than duplicated.
 */
final class AssistantEditControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // Denies anonymous access via the firewall — the edit route requires a signed-in user; the branded 401 entry point handles the anonymous case.
    public function testAnonymousRequestReturnsUnauthorized(): void
    {
        $assistant = $this->assistant();

        $this->client->request('GET', '/assistant/'.$assistant->getId().'/edit');

        self::assertResponseStatusCodeSame(401);
    }

    // Denies a signed-in user who is neither the author nor an admin.
    public function testForbidsUnrelatedSignedInUser(): void
    {
        $bob = $this->userByEmail(UserFixtures::BOB_EMAIL);
        $assistant = $this->assistantOwnedBy($this->userByEmail(UserFixtures::ALICE_EMAIL));
        $this->client->loginUser($bob);

        $this->client->request('GET', '/assistant/'.$assistant->getId().'/edit');

        self::assertResponseStatusCodeSame(403);
    }

    // Grants the assistant's original curator — step 1 renders with the persisted source config in the textarea.
    public function testAuthorSeesStepOnePrefilled(): void
    {
        $alice = $this->userByEmail(UserFixtures::ALICE_EMAIL);
        $assistant = $this->assistantOwnedBy($alice);
        $this->client->loginUser($alice);

        $crawler = $this->client->request('GET', '/assistant/'.$assistant->getId().'/edit');

        self::assertResponseIsSuccessful();
        $textarea = $crawler->filter('textarea[name$="[sourceConfig]"]')->text();
        self::assertNotSame('', trim($textarea), 'source config is pre-filled from the entity');
    }

    // Verifies the edit wizard renders correctly for an assistant with no attached organisation — the hydrated draft carries `organizationId = null` on the picker.
    public function testAuthorSeesStepOneForAssistantWithoutOrganization(): void
    {
        $alice = $this->userByEmail(UserFixtures::ALICE_EMAIL);
        $repository = self::getContainer()->get(AssistantRepository::class);
        $tagless = $repository->findOneBy(['title' => 'Uden kategorier']);
        self::assertNotNull($tagless, 'fixture baseline must include the tagless edge-case entry');
        self::assertNull($tagless->getOrganization(), 'this fixture row is used precisely because it has no organization');
        $tagless->setCreatedBy($alice);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();
        $this->client->loginUser($alice);

        $this->client->request('GET', '/assistant/'.$tagless->getId().'/edit');

        self::assertResponseIsSuccessful();
    }

    // Grants site admins on any assistant, regardless of authorship.
    public function testAdminSeesStepOneEvenWhenNotAuthor(): void
    {
        $admin = $this->promoteToAdmin($this->userByEmail(UserFixtures::BOB_EMAIL));
        $assistant = $this->assistantOwnedBy($this->userByEmail(UserFixtures::ALICE_EMAIL));
        $this->client->loginUser($admin);

        $this->client->request('GET', '/assistant/'.$assistant->getId().'/edit');

        self::assertResponseIsSuccessful();
    }

    // Verifies a GET after a completed edit clears the session slot so the same URL rerenders step 1 instead of the receipt.
    public function testGetAfterCompletionRestartsAtStepOne(): void
    {
        $alice = $this->userByEmail(UserFixtures::ALICE_EMAIL);
        $assistant = $this->assistantOwnedBy($alice);
        $this->client->loginUser($alice);

        // Walk through the wizard once so the session slot ends
        // with `createdAssistantId` set.
        $crawler = $this->client->request('GET', '/assistant/'.$assistant->getId().'/edit');
        $stepOne = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $sourceField = $this->findFieldName($stepOne->all(), '[sourceConfig]');
        $stepOne[$sourceField] = json_encode(['name' => 'Reset', 'base_model_id' => 'gpt-4o'], \JSON_THROW_ON_ERROR);
        $crawler = $this->client->submit($stepOne);
        $stepTwo = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $sensitivityField = $this->findFieldName($stepTwo->all(), '[dataSensitivity]');
        $stepTwo[$sensitivityField] = 'ordinary_personal';
        $this->client->submit($stepTwo);

        // Fresh GET after completion: session slot resets and step 1
        // renders instead of the receipt.
        $crawler = $this->client->request('GET', '/assistant/'.$assistant->getId().'/edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('textarea[name$="[sourceConfig]"]');
        $body = $crawler->filter('body')->text();
        self::assertStringNotContainsString('Ændringer er gemt', $body);
    }

    // Ensures a step-2 submission missing the required dataSensitivity re-renders the same step with a 422.
    public function testStepTwoRejectsMissingRequiredField(): void
    {
        $alice = $this->userByEmail(UserFixtures::ALICE_EMAIL);
        $assistant = $this->assistantOwnedBy($alice);
        $this->client->loginUser($alice);

        $crawler = $this->client->request('GET', '/assistant/'.$assistant->getId().'/edit');
        $stepOne = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $sourceField = $this->findFieldName($stepOne->all(), '[sourceConfig]');
        $stepOne[$sourceField] = json_encode(['name' => 'Rejected', 'base_model_id' => 'gpt-4o'], \JSON_THROW_ON_ERROR);
        $crawler = $this->client->submit($stepOne);

        // Step 2 with an unset dataSensitivity — the field's
        // NotNull constraint fires and the flow re-renders step 2.
        // DomCrawler's radio ChoiceFormField rejects an empty-string
        // assignment; disable its choice validation so we can force
        // the "no radio selected" state the constraint guards
        // against.
        $stepTwo = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $sensitivityField = $this->findFieldName($stepTwo->all(), '[dataSensitivity]');
        $stepTwo->get($sensitivityField)->disableValidation();
        $stepTwo[$sensitivityField] = '';
        $this->client->submit($stepTwo);

        self::assertResponseStatusCodeSame(422);
    }

    // Verifies pasting a different JSON on step 1 of the edit wizard refreshes step 2's derived fields — the persisted entity's metadata stops being authoritative once the curator explicitly swaps the raw config.
    public function testReuploadingDifferentJsonRefreshesStepTwoOnEdit(): void
    {
        $alice = $this->userByEmail(UserFixtures::ALICE_EMAIL);
        $assistant = $this->assistantOwnedBy($alice);
        $originalTitle = $assistant->getTitle();
        $this->client->loginUser($alice);

        $crawler = $this->client->request('GET', '/assistant/'.$assistant->getId().'/edit');

        // Confirm the edit wizard renders step 1 with the entity's
        // stored sourceConfig — a pre-condition for the refresh check
        // below to be meaningful.
        $stepOne = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $sourceField = $this->findFieldName($stepOne->all(), '[sourceConfig]');
        self::assertNotSame('', trim($stepOne[$sourceField]->getValue()));

        // Paste a different JSON and submit.
        $stepOne[$sourceField] = json_encode([
            'name' => 'Refreshed title',
            'base_model_id' => 'gpt-4o',
            'meta' => ['description' => 'Refreshed description'],
        ], \JSON_THROW_ON_ERROR);
        $crawler = $this->client->submit($stepOne);

        // Step 2 now shows the new JSON's title / description —
        // NOT the entity's original values.
        $stepTwo = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $titleField = $this->findFieldName($stepTwo->all(), '[title]');
        self::assertSame('Refreshed title', $stepTwo[$titleField]->getValue());
        self::assertNotSame($originalTitle, $stepTwo[$titleField]->getValue());
        $descriptionField = $this->findFieldName($stepTwo->all(), '[description]');
        self::assertSame('Refreshed description', $stepTwo[$descriptionField]->getValue());
    }

    // Verifies the step-2 "(Ændret)" badge surfaces on the edit wizard when a derived field no longer matches the persisted entity, whether via manual step-2 edit or a step-1 re-upload.
    public function testChangedBadgeAppearsForFieldsDivergingFromPersistedEntity(): void
    {
        $alice = $this->userByEmail(UserFixtures::ALICE_EMAIL);
        $assistant = $this->assistantOwnedBy($alice);
        $originalTitle = $assistant->getTitle();
        $assistant->setSourceConfig(['name' => $originalTitle, 'base_model_id' => $assistant->getLanguageModel()]);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();
        $this->client->loginUser($alice);

        // Baseline: entity → step 2 with the original title shows NO badge.
        $crawler = $this->client->request('GET', '/assistant/'.$assistant->getId().'/edit');
        $stepOne = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $crawler = $this->client->submit($stepOne);
        // Filter down to the badge itself — the rich-radio cards on
        // the data-sensitivity field also render `<label><span>…</span></label>`
        // shapes, and the "(Ændret)" badge is the only `<span>`
        // inside a `<label>` carrying `uppercase` styling.
        self::assertCount(
            0,
            $crawler->filter('label span.uppercase'),
            'no badge before any change lands on the derived fields',
        );

        // Paste a different JSON via Previous + submit-with-new-JSON.
        $stepTwoBack = $crawler->selectButton('assistant_create_flow[navigator][previous]')->form();
        $crawler = $this->client->submit($stepTwoBack);
        $stepOneAgain = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $sourceField = $this->findFieldName($stepOneAgain->all(), '[sourceConfig]');
        $stepOneAgain[$sourceField] = json_encode([
            'name' => 'Refreshed via wizard',
            'base_model_id' => $assistant->getLanguageModel(),
        ], \JSON_THROW_ON_ERROR);
        $crawler = $this->client->submit($stepOneAgain);

        // Step 2 now diverges from the entity — the badge appears as
        // a real `<label><span>Ændret</span></label>` structure, not
        // an escaped literal.
        $badges = $crawler->filter('label span.uppercase');
        self::assertGreaterThan(0, $badges->count(), 'at least one label carries the changed badge');
        self::assertSame('Ændret', trim($badges->first()->text()));
    }

    // Verifies pasting a JSON without tags on step 1 of the edit wizard clears step 2's tags field — the new config is authoritative even for "cleared" fields.
    public function testReuploadingJsonWithoutTagsClearsStepTwoTagsField(): void
    {
        $alice = $this->userByEmail(UserFixtures::ALICE_EMAIL);
        $assistant = $this->assistantOwnedBy($alice);
        // Precondition: the fixture assistant carries at least one tag.
        self::assertGreaterThan(0, $assistant->getTags()->count(), 'the fixture row must ship at least one tag');
        $this->client->loginUser($alice);

        $crawler = $this->client->request('GET', '/assistant/'.$assistant->getId().'/edit');
        $stepOne = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $sourceField = $this->findFieldName($stepOne->all(), '[sourceConfig]');
        // JSON without the `meta.tags` array.
        $stepOne[$sourceField] = json_encode([
            'name' => 'Tagless upload',
            'base_model_id' => $assistant->getLanguageModel(),
            'meta' => ['description' => 'No tags on this one'],
        ], \JSON_THROW_ON_ERROR);
        $crawler = $this->client->submit($stepOne);

        $stepTwo = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $tagsField = $this->findFieldName($stepTwo->all(), '[tags]');
        self::assertSame('', $stepTwo[$tagsField]->getValue(), 'tags field clears when the new JSON carries no tags');
    }

    // Verifies stepping through step 1 without changing the JSON leaves the entity-hydrated metadata intact.
    public function testUnchangedJsonPreservesEntityHydratedMetadata(): void
    {
        $alice = $this->userByEmail(UserFixtures::ALICE_EMAIL);
        $assistant = $this->assistantOwnedBy($alice);
        // Fixture rows carry no sourceConfig — set a valid one so
        // step 1's `ValidAssistantConfig` accepts the payload the
        // controller hydrates from the entity.
        $assistant->setSourceConfig(['name' => $assistant->getTitle(), 'base_model_id' => $assistant->getLanguageModel()]);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();
        $this->client->loginUser($alice);

        $crawler = $this->client->request('GET', '/assistant/'.$assistant->getId().'/edit');

        // Submit step 1 without changing the sourceConfig field.
        $stepOne = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $crawler = $this->client->submit($stepOne);

        $stepTwo = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $titleField = $this->findFieldName($stepTwo->all(), '[title]');
        self::assertSame($assistant->getTitle(), $stepTwo[$titleField]->getValue());
    }

    // Verifies POST /assistant/{id}/delete via the rendered trash-icon form removes the row and redirects to the personal inventory.
    public function testDeleteRemovesEntity(): void
    {
        $alice = $this->userByEmail(UserFixtures::ALICE_EMAIL);
        $assistant = $this->assistantOwnedBy($alice);
        $id = $assistant->getId();
        $this->client->loginUser($alice);

        // Submit the rendered form so its baked-in CSRF token is
        // used verbatim — the token manager needs an active session
        // to mint a value, which the client only spins up on first
        // request.
        $crawler = $this->client->request('GET', '/mine/assistenter');
        $form = $crawler->filter('form[action="/assistant/'.$id.'/delete"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/mine/assistenter');
        self::assertNull(self::getContainer()->get(AssistantRepository::class)->find($id));
    }

    // Ensures POST /assistant/{id}/delete with a bad CSRF token returns 403 and does not remove the row.
    public function testDeleteRejectsInvalidCsrfToken(): void
    {
        $alice = $this->userByEmail(UserFixtures::ALICE_EMAIL);
        $assistant = $this->assistantOwnedBy($alice);
        $id = $assistant->getId();
        $this->client->loginUser($alice);

        $this->client->request('POST', '/assistant/'.$id.'/delete', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull(self::getContainer()->get(AssistantRepository::class)->find($id));
    }

    // Denies deletion to a signed-in user who is neither the author nor an admin. The voter runs before the CSRF check, so any token value produces the same 403.
    public function testDeleteForbidsUnrelatedSignedInUser(): void
    {
        $alice = $this->userByEmail(UserFixtures::ALICE_EMAIL);
        $assistant = $this->assistantOwnedBy($alice);
        $bob = $this->userByEmail(UserFixtures::BOB_EMAIL);
        $this->client->loginUser($bob);

        $this->client->request('POST', '/assistant/'.$assistant->getId().'/delete', ['_token' => 'irrelevant']);

        self::assertResponseStatusCodeSame(403);
    }

    // Full happy path: step 1 → step 2 (pre-filled) → step 3, ending with the row updated in place.
    public function testHappyPathUpdatesRowInPlace(): void
    {
        $alice = $this->userByEmail(UserFixtures::ALICE_EMAIL);
        $assistant = $this->assistantOwnedBy($alice);
        $originalId = $assistant->getId();
        $this->client->loginUser($alice);

        $crawler = $this->client->request('GET', '/assistant/'.$originalId.'/edit');
        self::assertResponseIsSuccessful();

        // Step 1: submit with a fresh valid payload so the source
        // config is replaced along with the metadata below.
        $stepOne = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $sourceField = $this->findFieldName($stepOne->all(), '[sourceConfig]');
        $stepOne[$sourceField] = json_encode([
            'name' => 'Edited via wizard',
            'base_model_id' => 'gpt-4o',
            'meta' => ['description' => 'Rewritten by curator'],
        ], \JSON_THROW_ON_ERROR);
        $crawler = $this->client->submit($stepOne);

        self::assertResponseIsSuccessful();

        // Step 2: because the curator pasted a different sourceConfig
        // on step 1, the edit-path prefiller refreshes the derived
        // fields from the new canonical. The entity-hydrated values
        // are still what the persisted row carries — they just
        // aren't the current draft any more.
        $stepTwo = $crawler->selectButton('assistant_create_flow[navigator][next]')->form();
        $titleField = $this->findFieldName($stepTwo->all(), '[title]');
        self::assertSame('Edited via wizard', $stepTwo[$titleField]->getValue());

        // Rewrite a couple of fields.
        $stepTwo[$titleField] = 'Rewritten title';
        $descriptionField = $this->findFieldName($stepTwo->all(), '[description]');
        $stepTwo[$descriptionField] = 'Rewritten description';
        $sensitivityField = $this->findFieldName($stepTwo->all(), '[dataSensitivity]');
        $stepTwo[$sensitivityField] = 'ordinary_personal';

        $crawler = $this->client->submit($stepTwo);
        self::assertResponseIsSuccessful();

        // Step 3 shows the "Ændringer er gemt" receipt.
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Ændringer er gemt', $body);

        // The persisted row was mutated in place — same id.
        $reloaded = self::getContainer()->get(AssistantRepository::class)->find($originalId);
        self::assertNotNull($reloaded);
        self::assertSame('Rewritten title', $reloaded->getTitle());
        self::assertSame('Rewritten description', $reloaded->getDescription());
    }

    private function assistant(): Assistant
    {
        $assistant = self::getContainer()
            ->get(AssistantRepository::class)
            ->findOneBy(['title' => 'Borgerservice-vejviser']);
        self::assertNotNull($assistant, 'fixture baseline must include Borgerservice-vejviser');

        return $assistant;
    }

    private function assistantOwnedBy(User $owner): Assistant
    {
        $assistant = $this->assistant();
        $assistant->setCreatedBy($owner);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();

        return $assistant;
    }

    private function userByEmail(string $email): User
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('UserFixtures must seed %s.', $email));

        return $user;
    }

    private function promoteToAdmin(User $user): User
    {
        $user->setRoles([Roles::ADMIN]);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();

        return $user;
    }

    /**
     * Locate a field whose full name ends with the supplied suffix.
     *
     * Symfony's flow types nest field names under long
     * bracketed paths — this helper hides that from the assertions.
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

        self::fail(sprintf('Form field ending with "%s" not found. Available: %s', $suffix, implode(', ', array_keys($fields))));
    }
}
