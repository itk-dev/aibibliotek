<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Render-level coverage of the Form:Fieldset component.
 *
 * The component wraps a section of admin-form controls in a rounded
 * bordered panel with a labelled `<legend>`. Tests pin the shared
 * appearance utilities, the legend rendering, class-prop appending,
 * and pass-through of arbitrary call-site attributes so a Stimulus
 * controller can wrap the section without an extra `<div>`.
 */
final class FormFieldsetRenderTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    // Verifies the fieldset carries the shared panel-shaped utilities.
    public function testRendersSharedPanelUtilities(): void
    {
        $html = $this->renderInline('<twig:Form:Fieldset legend="Section">body</twig:Form:Fieldset>');

        self::assertMatchesRegularExpression('#<fieldset[^>]*class="[^"]*grid[^"]*gap-4[^"]*rounded-xl[^"]*border[^"]*border-line[^"]*bg-surface[^"]*p-6[^"]*"#', $html);
    }

    // Verifies the legend prop renders inside a <legend> with the shared typography.
    public function testLegendPropRendersAsLegendElement(): void
    {
        $html = $this->renderInline('<twig:Form:Fieldset legend="Recipient">body</twig:Form:Fieldset>');

        self::assertMatchesRegularExpression('#<legend[^>]*class="[^"]*px-2[^"]*font-medium[^"]*text-ink[^"]*"[^>]*>\s*Recipient\s*</legend>#', $html);
    }

    // Ensures block content renders inside the fieldset after the legend.
    public function testBlockContentRendersAfterLegend(): void
    {
        $html = $this->renderInline('<twig:Form:Fieldset legend="X"><p>body</p></twig:Form:Fieldset>');

        self::assertMatchesRegularExpression('#<legend[^>]*>\s*X\s*</legend>\s*<p>body</p>#s', $html);
    }

    // Verifies a call-site class prop appends to the default panel utilities.
    public function testClassPropAppendsToDefaultPanel(): void
    {
        $html = $this->renderInline('<twig:Form:Fieldset legend="X" class="mt-8">body</twig:Form:Fieldset>');

        self::assertMatchesRegularExpression('#<fieldset[^>]*class="[^"]*p-6 mt-8"#', $html);
    }

    // Ensures Stimulus wiring (data-controller / data-*-value) forwards onto the fieldset.
    public function testStimulusAttributesForwardOntoFieldset(): void
    {
        $html = $this->renderInline('<twig:Form:Fieldset legend="X" data-controller="email-preview" data-email-preview-url-value="/preview">body</twig:Form:Fieldset>');

        self::assertMatchesRegularExpression('#<fieldset[^>]*data-controller="email-preview"[^>]*>#', $html);
        self::assertMatchesRegularExpression('#<fieldset[^>]*data-email-preview-url-value="/preview"[^>]*>#', $html);
    }

    private function renderInline(string $source): string
    {
        return $this->twig->createTemplate($source)->render();
    }
}
