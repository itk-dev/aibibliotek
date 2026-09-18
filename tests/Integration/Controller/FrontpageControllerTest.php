<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Repository\AssistantRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end coverage of the frontpage controller and the assistant
 * rail / stats blocks it renders. Uses the baseline catalogue loaded
 * by `tests/bootstrap_integration.php` (see `AssistantFixtures`,
 * 21 entries — 6 detailed and 15 generated).
 */
final class FrontpageControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The frontpage is gated, so log in a baseline fixture user
        // before each test so the page-render assertions below see
        // actual content rather than an unauthorised response.
        $alice = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'alice@example.test']);
        \assert(null !== $alice, 'UserFixtures must seed alice@example.test.');
        $this->client->loginUser($alice);
    }

    // Tests that the frontpage rail links to the five newest fixture assistants in id-DESC order.
    public function testCardRailLinksToTheFiveNewestFixtureAssistants(): void
    {
        $repository = self::getContainer()->get(AssistantRepository::class);
        $expected = $repository->findBy([], ['id' => 'DESC'], 5);
        self::assertCount(5, $expected, 'fixture baseline must seed at least five assistants');

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $cardLinks = $crawler->filter('.assistant-card');
        self::assertCount(5, $cardLinks, 'rail surfaces the controller\'s id-DESC limit of 5');

        $hrefs = $cardLinks->each(static fn ($node) => $node->attr('href'));
        foreach ($expected as $assistant) {
            self::assertContains('/assistant/'.$assistant->getId(), $hrefs);
        }
        self::assertSame(
            '/assistant/'.$expected[0]->getId(),
            $hrefs[0],
            'newest fixture entry must lead the rail',
        );

        $railText = $crawler->filter('[aria-label="Eksempler på assistenter"]')->text();
        self::assertStringContainsString($expected[0]->getTitle(), $railText);
        self::assertStringContainsString($expected[0]->getLanguageModel(), $railText);
    }

    // Tests that submitting the frontpage search box lands on the catalogue with the query applied.
    public function testSearchBoxSubmitsToCatalogueWithQuery(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[role="search"]')->form();
        $form['q'] = 'journaliseringsassistent';
        $resultCrawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/search?q=', $this->client->getRequest()->getUri());
        self::assertCount(
            1,
            $resultCrawler->filter('.assistant-card'),
            'the query reaches the catalogue and narrows to the matching assistant',
        );
        self::assertSelectorTextContains('[aria-label="Aktive filtre"]', '"journaliseringsassistent"');
    }

    // Verifies the stats block reflects the fixture totals (21 assistants, 6 distinct language models).
    public function testStatsReflectFixtureCatalogueCounts(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $statsText = $crawler->filter('dl')->text();
        // AssistantFixtures seeds 21 rows across 6 distinct canonical
        // model ids drawn from config/model_map.yaml (gpt-4o,
        // gpt-4o-mini, o3-mini, llama-3.1, llama-3.2, mistral).
        self::assertStringContainsString('21', $statsText, 'Assistanter count = 21');
        self::assertStringContainsString('6', $statsText, 'Sprogmodeller count = 6');
    }
}
