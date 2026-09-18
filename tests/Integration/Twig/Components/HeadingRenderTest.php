<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Render-level coverage of the Heading component.
 *
 * Pins two additive extensions that the audit-and-swap pass
 * depends on: the small `xs` size token (`text-lg`) used by admin
 * dialog titles, and pass-through of call-site attributes (e.g.
 * `id`) so a heading can be the target of an `aria-labelledby`
 * on a wrapping `<dialog>` without wrapping the component in an
 * extra element.
 */
final class HeadingRenderTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    // Verifies size=xs resolves to a text-lg utility, filling the smallest step in the heading scale.
    public function testXsSizeRendersTextLg(): void
    {
        $html = $this->renderInline('<twig:Heading level="2" size="xs">Title</twig:Heading>');

        self::assertMatchesRegularExpression('#<h2[^>]*class="[^"]*text-lg[^"]*"#', $html);
        self::assertStringContainsString('Title', $html);
    }

    // Ensures xs still defaults to font-semibold so it reads as a heading.
    public function testXsDefaultWeightIsSemibold(): void
    {
        $html = $this->renderInline('<twig:Heading level="2" size="xs">Title</twig:Heading>');

        self::assertMatchesRegularExpression('#<h2[^>]*class="[^"]*font-semibold[^"]*"#', $html);
    }

    // Verifies call-site attributes (e.g. id) forward onto the heading element so a <dialog aria-labelledby> can point at it.
    public function testAttributesForwardOntoHeadingElement(): void
    {
        $html = $this->renderInline('<twig:Heading level="2" size="xs" id="dialog-title">Preview</twig:Heading>');

        self::assertMatchesRegularExpression('#<h2[^>]*id="dialog-title"[^>]*>#', $html);
    }

    // Ensures existing consumers (level + class only, no attributes) still render as before.
    public function testClassPropStillAppendsWithoutAttributes(): void
    {
        $html = $this->renderInline('<twig:Heading level="1" class="mb-6">Hello</twig:Heading>');

        self::assertMatchesRegularExpression('#<h1[^>]*class="[^"]*mb-6[^"]*"#', $html);
        self::assertStringContainsString('Hello', $html);
    }

    // Verifies size=caption renders the small eyebrow-shaped heading (text-xs uppercase tracking-widest text-ink).
    public function testCaptionSizeRendersSmallEyebrowShape(): void
    {
        $html = $this->renderInline('<twig:Heading level="2" size="caption">Aktive filtre</twig:Heading>');

        self::assertMatchesRegularExpression('#<h2[^>]*class="[^"]*text-xs[^"]*uppercase[^"]*tracking-widest[^"]*text-ink[^"]*"#', $html);
        self::assertStringContainsString('Aktive filtre', $html);
    }

    // Verifies size=caption-lg renders the slightly larger eyebrow-shaped heading (text-sm variant).
    public function testCaptionLargeSizeRendersLargerEyebrow(): void
    {
        $html = $this->renderInline('<twig:Heading level="3" size="caption-lg">Tags</twig:Heading>');

        self::assertMatchesRegularExpression('#<h3[^>]*class="[^"]*text-sm[^"]*uppercase[^"]*tracking-widest[^"]*"#', $html);
    }

    // Verifies the `muted` prop swaps text-ink for text-text-muted while keeping the rest of the size class intact.
    public function testMutedPropSwapsInkForMutedText(): void
    {
        $html = $this->renderInline('<twig:Heading level="3" size="caption-lg" muted>Tags</twig:Heading>');

        self::assertMatchesRegularExpression('#<h3[^>]*class="[^"]*text-text-muted[^"]*"#', $html);
        self::assertDoesNotMatchRegularExpression('#<h3[^>]*class="[^"]*text-ink[^"]*"#', $html);
    }

    // Ensures muted=false (default) still renders text-ink for existing sizes.
    public function testMutedFalseKeepsTextInk(): void
    {
        $html = $this->renderInline('<twig:Heading level="2" size="caption">Section</twig:Heading>');

        self::assertMatchesRegularExpression('#<h2[^>]*class="[^"]*text-ink[^"]*"#', $html);
    }

    private function renderInline(string $source): string
    {
        return $this->twig->createTemplate($source)->render();
    }
}
