<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Render-level coverage of the Form:Button component's `link` variant.
 *
 * `link` renders a text-styled button that sits inline in prose
 * (e.g. the "Preview" trigger next to Markdown help text on the
 * admin email-settings page). Unlike the pill-shaped default, the
 * `link` variant drops the shape/size padding so it can nest inside
 * a `<p>` alongside other links without visually diverging.
 */
final class FormButtonRenderTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    // Verifies the link variant renders the text-primary underlined treatment.
    public function testLinkVariantRendersTextPrimaryUnderline(): void
    {
        $html = $this->renderInline('<twig:Form:Button variant="link">Preview</twig:Form:Button>');

        self::assertMatchesRegularExpression('#<button[^>]*class="[^"]*text-primary[^"]*underline[^"]*hover:text-primary-hover[^"]*"#', $html);
    }

    // Ensures the link variant drops the pill/shape and size padding — it should not carry rounded-lg or px-4 py-2.
    public function testLinkVariantOmitsShapeAndSizePadding(): void
    {
        $html = $this->renderInline('<twig:Form:Button variant="link">Preview</twig:Form:Button>');

        self::assertDoesNotMatchRegularExpression('#<button[^>]*class="[^"]*rounded-lg[^"]*"#', $html);
        self::assertDoesNotMatchRegularExpression('#<button[^>]*class="[^"]*px-4[^"]*"#', $html);
    }

    // Ensures the non-link variants still carry shape + size (regression check for the branch).
    public function testDefaultVariantStillCarriesShapeAndSize(): void
    {
        $html = $this->renderInline('<twig:Form:Button>Save</twig:Form:Button>');

        self::assertMatchesRegularExpression('#<button[^>]*class="[^"]*rounded-lg[^"]*"#', $html);
        self::assertMatchesRegularExpression('#<button[^>]*class="[^"]*px-4[^"]*py-2[^"]*"#', $html);
    }

    private function renderInline(string $source): string
    {
        return $this->twig->createTemplate($source)->render();
    }
}
