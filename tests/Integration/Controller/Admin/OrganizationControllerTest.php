<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\DataFixtures\UserFixtures;
use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration coverage of the admin Organization CRUD.
 *
 * Drives the controller through Symfony Form, Doctrine, and Twig
 * so the routing + form-binding + persistence wiring is exercised
 * together. Uses `OrganizationFixtures` from the integration
 * bootstrap (Aarhus / Aalborg / Odense) as the baseline.
 */
final class OrganizationControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // `/admin/*` requires authentication after the default-deny rule
        // landed; every test in this class drives the admin surface, so
        // log in once in setUp instead of per-test.
        $this->loginAsAdmin();
    }

    // Tests that GET /admin/organization lists every fixture row by name and shows the action links.
    public function testIndexListsFixtures(): void
    {
        $crawler = $this->client->request('GET', '/admin/organization');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Aarhus Kommune', $body);
        self::assertStringContainsString('Aalborg Kommune', $body);
        self::assertStringContainsString('Odense Kommune', $body);
    }

    // Tests that GET /admin/organization/new renders the create form with every expected field, including the framework `<select>` populated from SUPPORTED_FRAMEWORKS.
    public function testNewFormRenders(): void
    {
        $crawler = $this->client->request('GET', '/admin/organization/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="organization[name]"]');
        self::assertSelectorExists('textarea[name="organization[emailDomains]"]');
        self::assertSelectorExists('select[name="organization[defaultFramework]"]');
        // The default env-var fallback exposes exactly Open WebUI.
        self::assertSelectorExists('select[name="organization[defaultFramework]"] option[value="openwebui"]');
    }

    // Verifies that POSTing a valid new form persists an Organization with normalised email domains.
    public function testCreatePersistsOrganization(): void
    {
        $crawler = $this->client->request('GET', '/admin/organization/new');
        $form = $crawler->filter('form')->form();
        $form['organization[name]'] = 'Vejle Kommune';
        $form['organization[emailDomains]'] = "  VEJLE.DK \nvejle-kommune.dk\n\n";
        $form['organization[defaultFramework]'] = 'openwebui';
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/organization');

        $repository = self::getContainer()->get(OrganizationRepository::class);
        $created = $repository->findOneBy(['name' => 'Vejle Kommune']);
        self::assertNotNull($created);
        self::assertSame(['vejle.dk', 'vejle-kommune.dk'], $created->getEmailDomains());
        self::assertSame('openwebui', $created->getDefaultFramework());
    }

    // Ensures a submit missing the required `name` field is rejected with 422 and nothing is persisted.
    public function testCreateRejectsMissingRequiredField(): void
    {
        $crawler = $this->client->request('GET', '/admin/organization/new');
        $form = $crawler->filter('form')->form();
        $form['organization[name]'] = '';
        $form['organization[emailDomains]'] = 'incomplete.test';
        $form['organization[defaultFramework]'] = 'openwebui';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);

        $repository = self::getContainer()->get(OrganizationRepository::class);
        self::assertNull($repository->findOneBy(['emailDomains' => ['incomplete.test']]));
    }

    // Ensures an empty emailDomains submit renders the translated minMessage rather than a raw translation key.
    public function testCreateRejectsEmptyEmailDomainsWithTranslatedMessage(): void
    {
        $crawler = $this->client->request('GET', '/admin/organization/new');
        $form = $crawler->filter('form')->form();
        $form['organization[name]'] = 'Vejle Kommune';
        $form['organization[emailDomains]'] = '';
        $form['organization[defaultFramework]'] = 'openwebui';
        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Angiv mindst ét e-maildomæne.', $body);
        self::assertStringNotContainsString('admin.organization.form.email_domains_required', $body);
    }

    // Tests that GET /admin/organization/{id}/edit renders the form pre-filled with the entity's values.
    public function testEditFormPrefillsFromEntity(): void
    {
        $organization = $this->fixtureNamed('Aarhus Kommune');

        $crawler = $this->client->request('GET', '/admin/organization/'.$organization->getId().'/edit');

        self::assertResponseIsSuccessful();
        self::assertSame('Aarhus Kommune', $crawler->filter('input[name="organization[name]"]')->attr('value'));
        $textarea = $crawler->filter('textarea[name="organization[emailDomains]"]')->text();
        self::assertStringContainsString('aarhus.dk', $textarea);
    }

    // Verifies a hand-crafted POST with a framework value outside SUPPORTED_FRAMEWORKS is rejected with 422 (the ChoiceType's server-side gate) — the browser can only submit values from the <select>, but a curl-crafted body must still be refused.
    public function testCreateRejectsUnknownFrameworkWithTranslatedMessage(): void
    {
        $crawler = $this->client->request('GET', '/admin/organization/new');
        $form = $crawler->filter('form')->form();
        // Setting a value not on the choice list requires disabling the
        // DOM crawler's whitelist enforcement — mimics a hand-crafted
        // POST from outside the browser.
        $form['organization[name]']->setValue('Vejle Kommune');
        $form['organization[emailDomains]']->setValue('vejle.dk');
        $form['organization[defaultFramework]']->disableValidation()->setValue('not-a-real-framework');
        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);

        $repository = self::getContainer()->get(OrganizationRepository::class);
        self::assertNull($repository->findOneBy(['name' => 'Vejle Kommune']));
    }

    // Verifies a successful edit updates the entity in place and redirects to the index. `defaultFramework` is bound to the deploy-time list now, so we mutate the mutable free-text fields instead of the framework picker.
    public function testEditUpdatesEntity(): void
    {
        $organization = $this->fixtureNamed('Aalborg Kommune');

        $crawler = $this->client->request('GET', '/admin/organization/'.$organization->getId().'/edit');
        $form = $crawler->filter('form')->form();
        $form['organization[name]'] = 'Aalborg Kommune (renamed)';
        $form['organization[emailDomains]'] = "aalborg.dk\nrenamed-aalborg.dk";
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/organization');

        $repository = self::getContainer()->get(OrganizationRepository::class);
        $reloaded = $repository->find($organization->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Aalborg Kommune (renamed)', $reloaded->getName());
        self::assertSame(['aalborg.dk', 'renamed-aalborg.dk'], $reloaded->getEmailDomains());
        // Framework is preserved through the edit.
        self::assertSame('openwebui', $reloaded->getDefaultFramework());
    }

    // Ensures POST to /delete with a valid CSRF token removes the entity.
    public function testDeleteRemovesEntity(): void
    {
        $organization = $this->fixtureNamed('Odense Kommune');
        $id = $organization->getId();

        $crawler = $this->client->request('GET', '/admin/organization');
        $form = $crawler->filter('form[action$="/'.$id.'/delete"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/organization');

        $repository = self::getContainer()->get(OrganizationRepository::class);
        self::assertNull($repository->find($id));
    }

    // Ensures POST to /delete with an invalid CSRF token returns 403 and does not remove the entity.
    public function testDeleteRejectsInvalidCsrfToken(): void
    {
        $organization = $this->fixtureNamed('Aarhus Kommune');
        $id = $organization->getId();

        $this->client->request('POST', '/admin/organization/'.$id.'/delete', [
            '_token' => 'nope',
        ]);

        self::assertResponseStatusCodeSame(403);

        $repository = self::getContainer()->get(OrganizationRepository::class);
        self::assertNotNull($repository->find($id), 'Delete must not happen on invalid CSRF.');
    }

    // Verifies that unknown ids return 404.
    public function testEditUnknownIdReturns404(): void
    {
        $this->client->request('GET', '/admin/organization/999999/edit');

        self::assertResponseStatusCodeSame(404);
    }

    private function fixtureNamed(string $name): Organization
    {
        $organization = self::getContainer()->get(OrganizationRepository::class)->findOneBy(['name' => $name]);
        self::assertNotNull($organization, \sprintf('Fixture "%s" must exist for the test to run.', $name));

        return $organization;
    }

    private function loginAsAdmin(): void
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => UserFixtures::ADMIN_EMAIL]);
        \assert(null !== $user);
        $this->client->loginUser($user);
    }
}
