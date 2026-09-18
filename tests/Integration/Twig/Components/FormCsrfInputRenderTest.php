<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Render-level coverage of the Form:CsrfInput component.
 *
 * The component renders the hidden CSRF `_token` field with the
 * `csrf-protection` Stimulus controller wired up so the paired
 * cookie is minted at submit time. Tests pin the emitted markup
 * so a future refactor can't drop the `data-controller` attribute
 * (the swap consolidated a pre-existing bug where two call sites
 * had lost that attribute silently). The `/register` page is the
 * simplest public consumer of the component, so it's the surface
 * used to render the component in a real request context (the
 * csrf_token() helper needs an active session).
 */
final class FormCsrfInputRenderTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // Verifies the component renders a hidden _token input with the csrf-protection Stimulus controller when included in a real page.
    public function testRendersHiddenTokenInputWithCsrfProtectionController(): void
    {
        $this->client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[type="hidden"][name="_token"][data-controller="csrf-protection"]');
    }

    // Ensures the token attribute carries a non-empty value seeded from the supplied intent.
    public function testEmitsNonEmptyTokenValue(): void
    {
        $crawler = $this->client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        $node = $crawler->filter('input[name="_token"]')->first();
        self::assertNotSame('', $node->attr('value') ?? '');
    }
}
