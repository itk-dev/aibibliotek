<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Repository\AssistantRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end coverage of the assistant detail page.
 *
 * Drives the controller through the real `MapEntity` param converter
 * and Twig render path so the controller + template + translation
 * keys are exercised together. Uses the baseline catalogue loaded by
 * `tests/bootstrap_integration.php` (see `AssistantFixtures`); each
 * test's mutations are rolled back by DAMA at tearDown.
 *
 * The detail page renders three tabs (Beskrivelse / Viden / JSON)
 * driven by the `?tab=` query parameter. The tests below walk each
 * one so the tab-partial include paths are covered.
 */
final class AssistantControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The detail page is gated, so log in a baseline fixture user
        // before each test so the assertions below see actual content
        // rather than an unauthorised response.
        $alice = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'alice@example.test']);
        \assert(null !== $alice, 'UserFixtures must seed alice@example.test.');
        $this->client->loginUser($alice);
    }

    // Tests that GET /assistant/{id} renders the title, description flow, meta aside, and tab bar with real organization / tagline / data-sensitivity values from the persisted row.
    public function testRendersDefaultTab(): void
    {
        $repository = self::getContainer()->get(AssistantRepository::class);
        $assistant = $repository->findOneBy(['title' => 'Borgerservice-vejviser']);
        self::assertNotNull($assistant, 'fixture baseline must include the Borgerservice-vejviser entry');

        $crawler = $this->client->request('GET', '/assistant/'.$assistant->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Borgerservice-vejviser');

        // Header eyebrow now reads the real organisation name
        // (Aarhus Kommune for this fixture row) + the language model,
        // and the header's tagline paragraph renders the entity's
        // stored tagline.
        $article = $crawler->filter('article')->text();
        self::assertStringContainsString('Aarhus Kommune', $article, 'header + breadcrumb render the real organisation name');
        self::assertStringContainsString($assistant->getTagline(), $article, 'tagline paragraph renders');

        // The header's framework and data-sensitivity chips were removed:
        // the meta aside (asserted below) already carries both, so the
        // article must not repeat either.
        self::assertStringNotContainsString('Fortrolige data', $article, 'data-sensitivity is the aside\'s job, not the header\'s');
        self::assertStringNotContainsString('Open WebUI', $article, 'framework is the aside\'s job, not the header\'s');

        // Meta aside carries the same real values.
        $runtime = $crawler->filter('.layout-content-with-asides dl')->text();
        self::assertStringContainsString('Open WebUI', $runtime);
        self::assertStringContainsString('gpt-4o', $runtime);
        self::assertStringContainsString('Aarhus Kommune', $runtime, 'meta aside "Oprindelseskommune" renders the real organisation');
        self::assertStringContainsString('Fortrolige data', $runtime, 'meta aside "Datafølsomhed" renders the enum label');

        // "Godkendt til" is retired — the sidebar must no longer show it.
        self::assertStringNotContainsString('Godkendt til', $runtime);

        // Default tab (beskrivelse) shows the description + tag chips.
        self::assertStringContainsString('Hjælper sagsbehandlere', $article);
        self::assertStringContainsString('borgerservice', $article);
        self::assertStringContainsString('social', $article);

        // The old AI-tags-placeholder line is gone from the Beskrivelse tab.
        self::assertStringNotContainsString('AI-foreslåede tags', $article);

        // Tabs render as anchors with ?tab= query strings and mark the current one.
        self::assertSelectorExists('nav[aria-label="Assistentdetaljer"] a[aria-current="page"]');
        self::assertSelectorTextContains('nav[aria-label="Assistentdetaljer"] a[aria-current="page"]', 'Beskrivelse');
    }

    // Verifies the fallback copy renders on the header + sidebar when an assistant carries no organisation and no data-sensitivity classification.
    public function testFallbackCopyForUnattachedAssistant(): void
    {
        $repository = self::getContainer()->get(AssistantRepository::class);
        $tagless = $repository->findOneBy(['title' => 'Uden kategorier']);
        self::assertNotNull($tagless, 'fixture baseline must include the tagless edge-case entry');
        // Force the fallback branches: strip the fixture's default
        // organisation + data-sensitivity so both aside slots hit the
        // "no value" copy.
        $tagless->setOrganization(null);
        $tagless->setDataSensitivity(null);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $crawler = $this->client->request('GET', '/assistant/'.$tagless->getId());

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Ingen tilknyttet organisation', $body);
        self::assertStringContainsString('Ikke klassificeret', $body);
    }

    // Verifies each whitelisted ?tab= value renders the matching partial heading.
    public function testEachTabRendersItsPartial(): void
    {
        $repository = self::getContainer()->get(AssistantRepository::class);
        $assistant = $repository->findOneBy(['title' => 'Borgerservice-vejviser']);
        self::assertNotNull($assistant);
        $base = '/assistant/'.$assistant->getId();

        $cases = [
            'viden' => ['heading' => 'Vejledning', 'tabLabel' => 'Viden'],
            'json' => ['heading' => 'Download konfiguration', 'tabLabel' => 'JSON'],
        ];

        foreach ($cases as $tab => $expected) {
            $this->client->request('GET', $base.'?tab='.$tab);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('article h2', $expected['heading'], "tab={$tab} must render its own H2 heading");
            self::assertSelectorTextContains('nav[aria-label="Assistentdetaljer"] a[aria-current="page"]', $expected['tabLabel']);
        }
    }

    // Ensures an unknown ?tab= value falls back to the default (beskrivelse) tab silently, no 4xx.
    public function testUnknownTabFallsBackToDefault(): void
    {
        $repository = self::getContainer()->get(AssistantRepository::class);
        $assistant = $repository->findOneBy(['title' => 'Borgerservice-vejviser']);
        self::assertNotNull($assistant);

        $crawler = $this->client->request('GET', '/assistant/'.$assistant->getId().'?tab=no-such-tab');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('nav[aria-label="Assistentdetaljer"] a[aria-current="page"]', 'Beskrivelse');
    }

    // Ensures the tags <ul> is omitted entirely when the assistant has no tags.
    public function testOmitsTagsSectionWhenAssistantHasNone(): void
    {
        $repository = self::getContainer()->get(AssistantRepository::class);
        $tagless = $repository->findOneBy(['title' => 'Uden kategorier']);
        self::assertNotNull($tagless, 'fixture baseline must include the tagless edge-case entry');

        $crawler = $this->client->request('GET', '/assistant/'.$tagless->getId());

        self::assertResponseIsSuccessful();
        // The tab bar is a <nav>, so `article ul` catches only the
        // content <ul> — tags-heading + list are omitted when empty.
        self::assertCount(0, $crawler->filter('article ul'), 'tags <ul> must be absent when the list is empty');
    }

    // Verifies that a non-existent assistant id returns a 404 response.
    public function testUnknownAssistantReturns404(): void
    {
        $this->client->request('GET', '/assistant/999999');

        self::assertResponseStatusCodeSame(404);
    }

    // Tests that the detail page offers a download link to the OpenWebUI export route.
    public function testDetailPageLinksToExport(): void
    {
        $repository = self::getContainer()->get(AssistantRepository::class);
        $assistant = $repository->findOneBy(['title' => 'Borgerservice-vejviser']);
        self::assertNotNull($assistant);

        $this->client->request('GET', '/assistant/'.$assistant->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/assistant/'.$assistant->getId().'/export"][download]');
    }

    // Tests that GET /assistant/{id}/export returns a downloadable array-of-one OpenWebUI model reflecting the entity.
    public function testExportReturnsDownloadableArrayOfOneModel(): void
    {
        $repository = self::getContainer()->get(AssistantRepository::class);
        $assistant = $repository->findOneBy(['title' => 'Borgerservice-vejviser']);
        self::assertNotNull($assistant);

        $this->client->request('GET', '/assistant/'.$assistant->getId().'/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString(
            'attachment',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );

        $payload = json_decode((string) $this->client->getResponse()->getContent(), associative: true);
        self::assertIsArray($payload);
        self::assertCount(1, $payload);
        self::assertSame('Borgerservice-vejviser', $payload[0]['name']);
        self::assertSame($assistant->getLanguageModel(), $payload[0]['base_model_id']);
        self::assertSame($assistant->getDescription(), $payload[0]['meta']['description']);
    }

    // Verifies a non-existent assistant id returns 404 for the export route as well.
    public function testExportUnknownAssistantReturns404(): void
    {
        $this->client->request('GET', '/assistant/999999/export');

        self::assertResponseStatusCodeSame(404);
    }

    // Verifies an explicit ?format= for a registered format exports successfully.
    public function testExportAcceptsRegisteredFormatQueryParam(): void
    {
        $repository = self::getContainer()->get(AssistantRepository::class);
        $assistant = $repository->findOneBy(['title' => 'Borgerservice-vejviser']);
        self::assertNotNull($assistant);

        $this->client->request('GET', '/assistant/'.$assistant->getId().'/export?format=openwebui');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    // Verifies a cross-format export whose model has no target equivalent sets a non-blocking warning header.
    public function testCrossFormatExportSetsWarningHeader(): void
    {
        $repository = self::getContainer()->get(AssistantRepository::class);
        $assistant = $repository->findOneBy(['title' => 'Borgerservice-vejviser']);
        self::assertNotNull($assistant);

        // The fixture runs on gpt-4o, which has no Ollama equivalent.
        $this->client->request('GET', '/assistant/'.$assistant->getId().'/export?format=ollama');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/plain', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertNotNull($this->client->getResponse()->headers->get('X-Export-Warning'));
    }

    // Verifies an unknown ?format= returns 404.
    public function testExportUnknownFormatReturns404(): void
    {
        $repository = self::getContainer()->get(AssistantRepository::class);
        $assistant = $repository->findOneBy(['title' => 'Borgerservice-vejviser']);
        self::assertNotNull($assistant);

        $this->client->request('GET', '/assistant/'.$assistant->getId().'/export?format=bogus');

        self::assertResponseStatusCodeSame(404);
    }
}
