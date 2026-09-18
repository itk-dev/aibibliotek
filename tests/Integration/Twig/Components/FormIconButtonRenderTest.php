<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Render-level coverage of the Form:IconButton component.
 *
 * Icon-only outlined button covering edit, delete, and modal-close
 * affordances across the app. Tests pin the shape / size / tone
 * axes, the button-vs-anchor swap when `href` is set, and the
 * pass-through of arbitrary call-site attributes (aria-label,
 * data-action) that make the affordance operable.
 */
final class FormIconButtonRenderTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    // Verifies the default (rounded, sm, primary) renders as an h-8 w-8 rounded-lg outlined button with primary hover.
    public function testDefaultRendersRoundedSmPrimary(): void
    {
        $html = $this->renderInline('<twig:Form:IconButton>x</twig:Form:IconButton>');

        self::assertMatchesRegularExpression('#<button[^>]*type="button"#', $html);
        self::assertMatchesRegularExpression('#<button[^>]*class="[^"]*h-8[^"]*w-8[^"]*"#', $html);
        self::assertMatchesRegularExpression('#<button[^>]*class="[^"]*rounded-lg[^"]*"#', $html);
        self::assertMatchesRegularExpression('#<button[^>]*class="[^"]*border[^"]*border-line[^"]*"#', $html);
        self::assertMatchesRegularExpression('#<button[^>]*class="[^"]*hover:border-primary[^"]*hover:text-primary[^"]*"#', $html);
    }

    // Ensures size=md renders h-9 w-9 (the modal close button and the assistant/show edit link).
    public function testSizeMdRendersLargerSquare(): void
    {
        $html = $this->renderInline('<twig:Form:IconButton size="md">x</twig:Form:IconButton>');

        self::assertMatchesRegularExpression('#<button[^>]*class="[^"]*h-9[^"]*w-9[^"]*"#', $html);
    }

    // Ensures shape=pill renders rounded-full (the modal close × sits inside a rounded-full outlined button).
    public function testShapePillRendersRoundedFull(): void
    {
        $html = $this->renderInline('<twig:Form:IconButton shape="pill">x</twig:Form:IconButton>');

        self::assertMatchesRegularExpression('#<button[^>]*class="[^"]*rounded-full[^"]*"#', $html);
    }

    // Verifies tone=danger renders the red hover treatment used by delete actions.
    public function testToneDangerRendersRedHover(): void
    {
        $html = $this->renderInline('<twig:Form:IconButton tone="danger">x</twig:Form:IconButton>');

        self::assertMatchesRegularExpression('#<button[^>]*class="[^"]*hover:border-red-600[^"]*hover:text-red-600[^"]*"#', $html);
    }

    // Verifies tone=neutral bumps the glyph size (`text-lg leading-none`) so a `×` character reads at button-scale.
    public function testToneNeutralAppliesGlyphSizing(): void
    {
        $html = $this->renderInline('<twig:Form:IconButton tone="neutral">x</twig:Form:IconButton>');

        self::assertMatchesRegularExpression('#<button[^>]*class="[^"]*text-lg[^"]*leading-none[^"]*"#', $html);
        self::assertMatchesRegularExpression('#<button[^>]*class="[^"]*hover:bg-surface-2[^"]*"#', $html);
    }

    // Ensures href renders an <a> instead of <button>, with no-underline for the anchor affordance.
    public function testHrefRendersAsAnchor(): void
    {
        $html = $this->renderInline('<twig:Form:IconButton href="/edit/1" aria-label="Edit">x</twig:Form:IconButton>');

        self::assertMatchesRegularExpression('#<a[^>]*href="/edit/1"#', $html);
        self::assertMatchesRegularExpression('#<a[^>]*class="no-underline[^"]*"#', $html);
    }

    // Ensures call-site attributes (aria-label, data-action, title) spread onto the rendered element.
    public function testCallSiteAttributesSpread(): void
    {
        $html = $this->renderInline('<twig:Form:IconButton type="button" aria-label="Close" data-action="click->email-preview#close">x</twig:Form:IconButton>');

        self::assertStringContainsString('aria-label="Close"', $html);
        // Twig auto-escapes the `>` in the Stimulus action value; the parsed DOM attribute reads as the un-escaped
        // `click->email-preview#close` string, so both raw and rendered forms are equivalent for the browser.
        self::assertStringContainsString('data-action="click-&gt;email-preview#close"', $html);
    }

    private function renderInline(string $source): string
    {
        return $this->twig->createTemplate($source)->render();
    }
}
