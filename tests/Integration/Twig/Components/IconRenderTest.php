<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Render-level coverage of the Icon component family.
 *
 * Each glyph (pencil, upload, trash) lives in its own file under
 * `templates/components/Icon/` and renders a decorative inline SVG.
 * Tests pin the wrapping <svg> attributes and check that each
 * component renders its intended `<path>` data so a design refresh
 * of one glyph can't silently swap another.
 */
final class IconRenderTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    // Verifies each glyph carries the shared presentation attributes (currentColor, aria-hidden, viewBox, default sizing class).
    public function testEachGlyphCarriesSharedSvgAttributes(): void
    {
        foreach (['Pencil', 'Upload', 'Trash'] as $glyph) {
            $html = $this->renderInline('<twig:Icon:'.$glyph.' />');

            self::assertMatchesRegularExpression('#<svg[^>]*stroke="currentColor"#', $html, $glyph);
            self::assertMatchesRegularExpression('#<svg[^>]*aria-hidden="true"#', $html, $glyph);
            self::assertMatchesRegularExpression('#<svg[^>]*class="h-5 w-5"#', $html, $glyph);
            self::assertMatchesRegularExpression('#<svg[^>]*viewBox="0 0 24 24"#', $html, $glyph);
        }
    }

    // Ensures the `class` prop overrides the default sizing utilities on each glyph.
    public function testClassPropOverridesDefaultSize(): void
    {
        $html = $this->renderInline('<twig:Icon:Upload class="h-10 w-10 text-text-muted" />');

        self::assertMatchesRegularExpression('#<svg[^>]*class="h-10 w-10 text-text-muted"#', $html);
    }

    // Verifies the pencil glyph renders the edit path.
    public function testPencilRendersEditPath(): void
    {
        $html = $this->renderInline('<twig:Icon:Pencil />');

        self::assertStringContainsString('d="M16.862 4.487', $html);
    }

    // Verifies the upload glyph renders the upload arrow path.
    public function testUploadRendersUploadArrowPath(): void
    {
        $html = $this->renderInline('<twig:Icon:Upload />');

        self::assertStringContainsString('d="M3 16.5v2.25', $html);
    }

    // Verifies the trash glyph renders the trash-can path.
    public function testTrashRendersTrashCanPath(): void
    {
        $html = $this->renderInline('<twig:Icon:Trash />');

        self::assertStringContainsString('d="M14.74 9l', $html);
    }

    // Ensures call-site attributes (e.g. data-*) spread onto the <svg> element.
    public function testCallSiteAttributesSpreadOntoSvg(): void
    {
        $html = $this->renderInline('<twig:Icon:Pencil data-testid="edit-icon" />');

        self::assertStringContainsString('data-testid="edit-icon"', $html);
    }

    private function renderInline(string $source): string
    {
        return $this->twig->createTemplate($source)->render();
    }
}
