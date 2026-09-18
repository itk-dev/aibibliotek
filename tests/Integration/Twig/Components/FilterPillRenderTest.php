<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Render-level coverage of the Filter:Pill component.
 *
 * `Filter:Pill` is a plain navigation link — the pill visually
 * toggles between an active (filled) and inactive (link-colored)
 * state depending on whether it represents the current selection.
 * The tests pin the class swap so an accidental prop rename can't
 * silently drop the active affordance.
 */
final class FilterPillRenderTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    // Verifies the inactive state renders link-colored text with a hover fill.
    public function testInactiveStateUsesTextAndHoverClasses(): void
    {
        $html = $this->renderInline('<twig:Filter:Pill href="/x" label="Alle" />');

        self::assertMatchesRegularExpression('#<a[^>]*href="/x"#', $html);
        self::assertMatchesRegularExpression('#<a[^>]*class="[^"]*text-text[^"]*hover:bg-surface-2[^"]*"#', $html);
        self::assertStringContainsString('Alle', $html);
    }

    // Verifies the active state fills the pill background.
    public function testActiveStateSwapsToFilledBackground(): void
    {
        $html = $this->renderInline('<twig:Filter:Pill href="/x" label="Alle" active="true" />');

        self::assertMatchesRegularExpression('#<a[^>]*class="[^"]*bg-surface-2[^"]*text-ink[^"]*"#', $html);
    }

    // Ensures the pill always carries the shared rounded-full border shape so active/inactive stay visually aligned.
    public function testSharedShapeClassesAreAlwaysPresent(): void
    {
        $inactive = $this->renderInline('<twig:Filter:Pill href="/x" label="a" />');
        $active = $this->renderInline('<twig:Filter:Pill href="/x" label="a" active="true" />');

        foreach ([$inactive, $active] as $html) {
            self::assertMatchesRegularExpression('#<a[^>]*class="[^"]*rounded-full[^"]*border[^"]*border-line[^"]*"#', $html);
        }
    }

    private function renderInline(string $source): string
    {
        return $this->twig->createTemplate($source)->render();
    }
}
