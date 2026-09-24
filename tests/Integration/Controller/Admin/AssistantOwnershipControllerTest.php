<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\DataFixtures\UserFixtures;
use App\Entity\Assistant;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\AssistantRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Security\Roles;
use App\Security\UserManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Integration coverage of the admin assistant-ownership screen.
 *
 * Drives the listing and the bulk reassignment through routing,
 * authorisation, Doctrine, and Twig together. The baseline is the
 * integration bootstrap's fixture set: Aarhus / Aalborg / Odense
 * organisations, assistants whose `organization` points at some of
 * them, and users seeded on matching e-mail domains.
 */
final class AssistantOwnershipControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // Tests that a domain manager sees their own organisation's assistants and no others.
    public function testListShowsOnlyTheManagersOrganizationAssistants(): void
    {
        $this->loginAs(UserFixtures::DOMAIN_MANAGER_EMAIL);

        $crawler = $this->client->request('GET', '/admin/assistants');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Borgerservice-vejviser', $body, 'The Aarhus assistant must be listed.');
        self::assertStringNotContainsString('Journaliseringsassistent', $body, 'The Odense assistant must not leak into the Aarhus listing.');
        self::assertStringNotContainsString('Tilsynsrapport-assistent', $body, 'The Aalborg assistant must not leak into the Aarhus listing.');
    }

    // Ensures the organisation picker is hidden from a domain manager, who manages exactly one.
    public function testListHidesTheOrganizationPickerFromADomainManager(): void
    {
        $this->loginAs(UserFixtures::DOMAIN_MANAGER_EMAIL);

        $this->client->request('GET', '/admin/assistants');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('select[name="organization"]');
    }

    // Ensures the new-owner picker offers only approved users on the organisation's domains.
    public function testOwnerPickerOffersOnlyApprovedOrganizationMembers(): void
    {
        $this->loginAs(UserFixtures::DOMAIN_MANAGER_EMAIL);

        $crawler = $this->client->request('GET', '/admin/assistants');

        $options = $crawler->filter('select[name="owner"] option')->each(static fn (Crawler $option): string => $option->text());
        $rendered = implode("\n", $options);
        self::assertStringContainsString(UserFixtures::COLLEAGUE_EMAIL, $rendered, 'An approved aarhus.dk user must be offered.');
        self::assertStringNotContainsString(UserFixtures::PENDING_EMAIL, $rendered, 'A pending user on another domain must not be offered.');
        self::assertStringNotContainsString(UserFixtures::ALICE_EMAIL, $rendered, 'A user outside the organisation must not be offered.');
    }

    // Verifies a site admin gets an organisation picker listing every organisation.
    public function testSiteAdminGetsAnOrganizationPicker(): void
    {
        $this->loginAs(UserFixtures::ADMIN_EMAIL);

        $crawler = $this->client->request('GET', '/admin/assistants');

        self::assertResponseIsSuccessful();
        $options = implode("\n", $crawler->filter('select[name="organization"] option')->each(static fn (Crawler $option): string => $option->text()));
        self::assertStringContainsString('Aarhus Kommune', $options);
        self::assertStringContainsString('Aalborg Kommune', $options);
        self::assertStringContainsString('Odense Kommune', $options);
    }

    // Verifies a site admin can switch the listing to another organisation via the query string.
    public function testSiteAdminCanSwitchOrganization(): void
    {
        $this->loginAs(UserFixtures::ADMIN_EMAIL);
        $odense = $this->organization('Odense Kommune');

        $crawler = $this->client->request('GET', '/admin/assistants?organization='.$odense->getId());

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Journaliseringsassistent', $body);
        self::assertStringNotContainsString('Borgerservice-vejviser', $body);
    }

    // Ensures an unknown organisation id falls back to the operator's default rather than erroring.
    public function testUnknownOrganizationIdFallsBackToTheDefault(): void
    {
        $this->loginAs(UserFixtures::DOMAIN_MANAGER_EMAIL);

        $crawler = $this->client->request('GET', '/admin/assistants?organization=01ARZ3NDEKTSV4RRFFQ69G5FAV');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Borgerservice-vejviser', $crawler->filter('body')->text());
    }

    // Ensures a plain signed-in user is refused the screen entirely.
    public function testPlainUserIsForbidden(): void
    {
        $this->loginAs(UserFixtures::COLLEAGUE_EMAIL);

        $this->client->request('GET', '/admin/assistants');

        self::assertResponseStatusCodeSame(403);
    }

    // Verifies a bulk reassignment moves `createdBy` on every selected assistant.
    public function testBulkReassignmentMovesOwnership(): void
    {
        $this->loginAs(UserFixtures::DOMAIN_MANAGER_EMAIL);
        $aarhus = $this->organization('Aarhus Kommune');
        $colleague = $this->user(UserFixtures::COLLEAGUE_EMAIL);
        $assistants = self::getContainer()->get(AssistantRepository::class)->findByOrganization($aarhus);
        self::assertNotEmpty($assistants, 'Fixtures must give Aarhus at least one assistant to reassign.');

        $this->submitReassignment($aarhus, $colleague, $assistants);

        self::assertResponseRedirects('/admin/assistants?organization='.$aarhus->getId());
        foreach ($assistants as $assistant) {
            $owner = $this->reload($assistant)->getCreatedBy();
            self::assertInstanceOf(User::class, $owner, 'A reassigned assistant must be owned by a user.');
            self::assertSame($colleague->getId()->toRfc4122(), $owner->getId()->toRfc4122());
        }
    }

    // Ensures an empty selection changes nothing and reports the error inline.
    public function testEmptySelectionIsRejected(): void
    {
        $this->loginAs(UserFixtures::DOMAIN_MANAGER_EMAIL);
        $aarhus = $this->organization('Aarhus Kommune');
        $before = $this->ownerIds($aarhus);

        $this->submitReassignment($aarhus, $this->user(UserFixtures::COLLEAGUE_EMAIL), []);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Vælg mindst én assistent');
        self::assertSame($before, $this->ownerIds($aarhus), 'No ownership may move on an empty selection.');
    }

    // Ensures a new owner from outside the organisation is refused and nothing is written.
    public function testOwnerOutsideTheOrganizationIsRejected(): void
    {
        $this->loginAs(UserFixtures::DOMAIN_MANAGER_EMAIL);
        $aarhus = $this->organization('Aarhus Kommune');
        $before = $this->ownerIds($aarhus);
        $assistants = self::getContainer()->get(AssistantRepository::class)->findByOrganization($aarhus);

        $this->submitReassignment($aarhus, $this->user(UserFixtures::ALICE_EMAIL), $assistants);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'kan ikke overtage assistenter');
        self::assertSame($before, $this->ownerIds($aarhus), 'A rejected transfer must leave every owner untouched.');
    }

    // Ensures an assistant from another organisation smuggled into the payload rejects the whole batch.
    public function testAssistantFromAnotherOrganizationRejectsTheBatch(): void
    {
        $this->loginAs(UserFixtures::ADMIN_EMAIL);
        $aarhus = $this->organization('Aarhus Kommune');
        $odense = $this->organization('Odense Kommune');
        $assistants = self::getContainer()->get(AssistantRepository::class)->findByOrganization($aarhus);
        $foreign = self::getContainer()->get(AssistantRepository::class)->findByOrganization($odense);
        self::assertNotEmpty($foreign, 'Fixtures must give Odense at least one assistant to smuggle in.');
        $before = $this->ownerIds($aarhus);

        $this->submitReassignment($aarhus, $this->user(UserFixtures::COLLEAGUE_EMAIL), [...$assistants, $foreign[0]]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'hører ikke til denne organisation');
        self::assertSame($before, $this->ownerIds($aarhus), 'The whole batch must be refused, not partially applied.');
    }

    // Ensures a domain manager cannot reassign an assistant belonging to another organisation.
    public function testDomainManagerCannotTransferAnotherOrganizationsAssistant(): void
    {
        $this->loginAs(UserFixtures::DOMAIN_MANAGER_EMAIL);
        $aarhus = $this->organization('Aarhus Kommune');
        $odense = $this->organization('Odense Kommune');
        $foreign = self::getContainer()->get(AssistantRepository::class)->findByOrganization($odense);
        $ownerBefore = $this->ownerId($foreign[0]);

        $this->submitReassignment($aarhus, $this->user(UserFixtures::COLLEAGUE_EMAIL), [$foreign[0]]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($ownerBefore, $this->ownerId($this->reload($foreign[0])));
    }

    // Ensures a malformed owner id is treated as an unknown user rather than raising a conversion error.
    public function testMalformedOwnerIdIsRejected(): void
    {
        $this->loginAs(UserFixtures::DOMAIN_MANAGER_EMAIL);
        $aarhus = $this->organization('Aarhus Kommune');
        $assistants = self::getContainer()->get(AssistantRepository::class)->findByOrganization($aarhus);

        $this->client->request('POST', '/admin/assistants/reassign', [
            '_token' => $this->csrfToken(),
            'organization' => (string) $aarhus->getId(),
            'owner' => 'not-a-ulid',
            'assistants' => array_map(static fn (Assistant $a): string => (string) $a->getId(), $assistants),
        ]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'kan ikke overtage assistenter');
    }

    // Ensures malformed assistant ids are dropped, leaving an empty selection to be refused.
    public function testMalformedAssistantIdsAreDropped(): void
    {
        $this->loginAs(UserFixtures::DOMAIN_MANAGER_EMAIL);
        $aarhus = $this->organization('Aarhus Kommune');

        $this->client->request('POST', '/admin/assistants/reassign', [
            '_token' => $this->csrfToken(),
            'organization' => (string) $aarhus->getId(),
            'owner' => (string) $this->user(UserFixtures::COLLEAGUE_EMAIL)->getId(),
            'assistants' => ['not-a-ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
        ]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Vælg mindst én assistent');
    }

    // Ensures a reassignment submitted without a valid CSRF token is refused.
    public function testReassignmentRequiresACsrfToken(): void
    {
        $this->loginAs(UserFixtures::DOMAIN_MANAGER_EMAIL);
        $aarhus = $this->organization('Aarhus Kommune');

        $this->client->request('POST', '/admin/assistants/reassign', [
            '_token' => 'wrong',
            'organization' => (string) $aarhus->getId(),
            'owner' => (string) $this->user(UserFixtures::COLLEAGUE_EMAIL)->getId(),
            'assistants' => [],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // Ensures a domain manager on a domain no organisation claims gets an empty state rather than another organisation's data.
    public function testManagerWithoutAnOrganizationSeesAnEmptyState(): void
    {
        $this->client->loginUser($this->managerWithoutOrganization());

        $crawler = $this->client->request('GET', '/admin/assistants');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('hører ikke til en registreret organisation', $crawler->filter('body')->text());
        self::assertSelectorNotExists('select[name="owner"]');
    }

    // Ensures a reassignment POSTed by a manager with no organisation 404s instead of guessing one.
    public function testReassignmentWithoutAnOrganizationIsNotFound(): void
    {
        $manager = $this->managerWithoutOrganization();
        $aarhus = $this->organization('Aarhus Kommune');
        // Mint the token while logged in as a manager who *does* have a
        // page to render it on, then switch to the organisation-less one.
        $this->loginAs(UserFixtures::DOMAIN_MANAGER_EMAIL);
        $token = $this->csrfToken();
        $this->client->loginUser($manager);

        $this->client->request('POST', '/admin/assistants/reassign', [
            '_token' => $token,
            'organization' => (string) $aarhus->getId(),
            'owner' => (string) $this->user(UserFixtures::COLLEAGUE_EMAIL)->getId(),
            'assistants' => [],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // Ensures an assistant carrying no organisation at all is refused rather than silently swept into a transfer.
    public function testAssistantWithoutAnOrganizationRejectsTheBatch(): void
    {
        // As a site admin: the voter grants them every assistant, so the
        // organisation check in the service is what has to catch this one.
        // A domain manager is stopped one layer earlier, with a 403.
        $this->loginAs(UserFixtures::ADMIN_EMAIL);
        $aarhus = $this->organization('Aarhus Kommune');
        $orphan = self::getContainer()->get(AssistantRepository::class)->findOneBy(['organization' => null]);
        self::assertInstanceOf(Assistant::class, $orphan, 'Fixtures must seed an assistant with no organisation.');
        $ownerBefore = $this->ownerId($orphan);

        $this->submitReassignment($aarhus, $this->user(UserFixtures::COLLEAGUE_EMAIL), [$orphan]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'hører ikke til denne organisation');
        self::assertSame($ownerBefore, $this->ownerId($this->reload($orphan)));
    }

    /**
     * Create an approved domain manager whose e-mail domain no
     * organisation claims — the state a manager lands in when their
     * municipality has not been registered (or was deleted).
     */
    private function managerWithoutOrganization(): User
    {
        return self::getContainer()->get(UserManager::class)->createUser(
            'stray@unclaimed.test',
            'Stray Manager',
            'password',
            [Roles::DOMAIN_MANAGER],
            UserStatus::Approved,
        );
    }

    /**
     * Submit the reassignment form with a valid CSRF token.
     *
     * @param list<Assistant> $assistants the assistants to select
     */
    private function submitReassignment(Organization $organization, User $newOwner, array $assistants): void
    {
        $this->client->request('POST', '/admin/assistants/reassign', [
            '_token' => $this->csrfToken(),
            'organization' => (string) $organization->getId(),
            'owner' => (string) $newOwner->getId(),
            'assistants' => array_map(static fn (Assistant $a): string => (string) $a->getId(), $assistants),
        ]);
    }

    /**
     * Mint a CSRF token for the reassignment intent, matching the one the
     * template renders.
     */
    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/assistants');
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        self::assertNotNull($token, 'The reassignment form must render a CSRF token.');

        return $token;
    }

    /**
     * Map each of the organisation's assistants to its current owner id,
     * so a test can assert nothing moved.
     *
     * @return array<string, string> assistant id → owner id, empty string when unowned
     */
    private function ownerIds(Organization $organization): array
    {
        $owners = [];
        foreach (self::getContainer()->get(AssistantRepository::class)->findByOrganization($organization) as $assistant) {
            $owners[(string) $assistant->getId()] = $this->ownerId($assistant);
        }

        return $owners;
    }

    /**
     * Read an assistant's current owner as a comparable string.
     *
     * The blameable trait types `createdBy` as the framework's
     * `UserInterface`, which carries no identity of its own, so narrow to
     * the application user before reading the ULID. An unowned assistant
     * maps to the empty string.
     */
    private function ownerId(Assistant $assistant): string
    {
        $owner = $assistant->getCreatedBy();

        return $owner instanceof User ? (string) $owner->getId() : '';
    }

    private function reload(Assistant $assistant): Assistant
    {
        $reloaded = self::getContainer()->get(AssistantRepository::class)->find($assistant->getId());
        self::assertInstanceOf(Assistant::class, $reloaded);

        return $reloaded;
    }

    private function organization(string $name): Organization
    {
        $organization = self::getContainer()->get(OrganizationRepository::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Organization::class, $organization, 'OrganizationFixtures must seed '.$name.'.');

        return $organization;
    }

    private function user(string $email): User
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, 'UserFixtures must seed '.$email.'.');

        return $user;
    }

    private function loginAs(string $email): void
    {
        $this->client->loginUser($this->user($email));
    }
}
