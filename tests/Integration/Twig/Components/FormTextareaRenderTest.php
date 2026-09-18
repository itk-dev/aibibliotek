<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Render-level coverage of the Form:Textarea component.
 *
 * The component covers the free-text (`prose`) and Markdown / template-
 * token (`mono`) variants across the admin settings surface. Tests pin
 * the shared appearance utilities, the variant swap, and the
 * pass-through of arbitrary call-site attributes (e.g. Stimulus
 * targets) so a design refresh of one variant can't silently break the
 * other.
 */
final class FormTextareaRenderTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    // Verifies the default (prose) variant renders the shared border/focus utilities and text-base sizing.
    public function testDefaultVariantRendersProseSizing(): void
    {
        $html = $this->renderInline('<twig:Form:Textarea id="i" name="n" />');

        self::assertMatchesRegularExpression('#<textarea[^>]*id="i"#', $html);
        self::assertMatchesRegularExpression('#<textarea[^>]*name="n"#', $html);
        self::assertMatchesRegularExpression('#<textarea[^>]*class="[^"]*rounded-lg[^"]*border[^"]*"#', $html);
        self::assertMatchesRegularExpression('#<textarea[^>]*class="[^"]*text-base[^"]*text-ink[^"]*"#', $html);
    }

    // Ensures variant=mono swaps to the monospaced small-text token set.
    public function testMonoVariantRendersMonoSizing(): void
    {
        $html = $this->renderInline('<twig:Form:Textarea id="i" name="n" variant="mono" />');

        self::assertMatchesRegularExpression('#<textarea[^>]*class="[^"]*font-mono[^"]*text-sm[^"]*"#', $html);
    }

    // Verifies the rows prop lands on the rendered element.
    public function testRowsPropIsHonored(): void
    {
        $html = $this->renderInline('<twig:Form:Textarea id="i" name="n" rows="8" />');

        self::assertMatchesRegularExpression('#<textarea[^>]*rows="8"#', $html);
    }

    // Ensures the value prop renders between the tags so form submits round-trip the previous input.
    public function testValueRendersAsElementContent(): void
    {
        $html = $this->renderInline('<twig:Form:Textarea id="i" name="n" value="hello world" />');

        self::assertMatchesRegularExpression('#<textarea[^>]*>hello world</textarea>#', $html);
    }

    // Ensures call-site attributes (e.g. Stimulus targets) spread onto the textarea element.
    public function testCallSiteAttributesSpreadOntoTextarea(): void
    {
        $html = $this->renderInline('<twig:Form:Textarea id="i" name="n" data-email-preview-target="bodyInput" />');

        self::assertStringContainsString('data-email-preview-target="bodyInput"', $html);
    }

    private function renderInline(string $source): string
    {
        return $this->twig->createTemplate($source)->render();
    }
}
