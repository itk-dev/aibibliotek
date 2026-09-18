<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Render-level coverage of the three page-layout components.
 *
 * Each variant is rendered against an inline template and asserted
 * structurally so that the contract between consumers and the grid
 * CSS classes in `assets/styles/app.css` stays pinned. Visual
 * breakpoint behavior is left to manual browser verification per
 * the issue's acceptance criteria.
 */
final class LayoutRenderTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    // Verifies SingleColumn renders the view-root animation hook around its content.
    public function testSingleColumnRendersViewRootGrid(): void
    {
        $html = $this->renderInline(<<<'TWIG'
            <twig:Layout:SingleColumn>
                <p>flow</p>
            </twig:Layout:SingleColumn>
            TWIG);

        // The single-column variant is a flow container; it keeps
        // the view-root fade-up hook on its direct children but
        // applies no extra horizontal constraint of its own (the
        // wide <main> sets the page width, inner components set
        // their own section widths).
        self::assertMatchesRegularExpression('#<div[^>]*class="[^"]*view-root[^"]*grid[^"]*grid-cols-1[^"]*"[^>]*>\s*<p>flow</p>\s*</div>#s', $html);
    }

    // Tests that SingleColumn appends a caller-supplied `class` prop to its wrapper.
    public function testSingleColumnClassPropAppends(): void
    {
        $html = $this->renderInline('<twig:Layout:SingleColumn class="mt-8"><p>x</p></twig:Layout:SingleColumn>');

        self::assertMatchesRegularExpression('#<div[^>]*class="[^"]*view-root[^"]*mt-8[^"]*"#', $html);
    }

    // Verifies ThreeColumn renders three slot landmarks in order with the grid class.
    public function testThreeColumnRendersStartMainEndSlots(): void
    {
        $html = $this->renderInline(<<<'TWIG'
            <twig:Layout:ThreeColumn>
                <twig:block name="start"><p>filters</p></twig:block>
                <twig:block name="main"><p>results</p></twig:block>
                <twig:block name="end"><p>rail</p></twig:block>
            </twig:Layout:ThreeColumn>
            TWIG);

        self::assertStringContainsString('layout-three-column', $html);
        self::assertMatchesRegularExpression('#<aside>\s*<p>filters</p>\s*</aside>\s*<div>\s*<p>results</p>\s*</div>\s*<aside>\s*<p>rail</p>\s*</aside>#s', $html);
    }

    // Ensures ThreeColumn still emits all three column elements when the `end` slot is omitted, so the grid keeps its cell shape.
    public function testThreeColumnEmitsEndAsideEvenWhenEmpty(): void
    {
        $html = $this->renderInline(<<<'TWIG'
            <twig:Layout:ThreeColumn>
                <twig:block name="start"><p>filters</p></twig:block>
                <twig:block name="main"><p>results</p></twig:block>
            </twig:Layout:ThreeColumn>
            TWIG);

        // Two non-empty asides and one main div, plus the trailing empty aside.
        self::assertSame(2, substr_count($html, '<aside>'));
    }

    // Verifies ContentWithAsides renders main + two sticky asides in the expected order.
    public function testContentWithAsidesRendersMainMetaActionsSlots(): void
    {
        $html = $this->renderInline(<<<'TWIG'
            <twig:Layout:ContentWithAsides>
                <twig:block name="main"><p>body</p></twig:block>
                <twig:block name="meta"><p>meta</p></twig:block>
                <twig:block name="actions"><p>actions</p></twig:block>
            </twig:Layout:ContentWithAsides>
            TWIG);

        self::assertStringContainsString('layout-content-with-asides', $html);
        self::assertMatchesRegularExpression('#<div>\s*<p>body</p>\s*</div>\s*<aside[^>]*class="layout-aside-sticky"[^>]*>\s*<p>meta</p>\s*</aside>\s*<aside[^>]*class="layout-aside-sticky"[^>]*>\s*<p>actions</p>\s*</aside>#s', $html);
    }

    // Tests that ContentWithAsides accepts a caller-supplied `class` prop that appends to the grid wrapper.
    public function testContentWithAsidesClassPropAppends(): void
    {
        $html = $this->renderInline('<twig:Layout:ContentWithAsides class="mt-4"><twig:block name="main">x</twig:block></twig:Layout:ContentWithAsides>');

        self::assertMatchesRegularExpression('#<div[^>]*class="layout-content-with-asides mt-4"#', $html);
    }

    // Tests that ThreeColumn accepts a caller-supplied `class` prop that appends to the grid wrapper.
    public function testThreeColumnClassPropAppends(): void
    {
        $html = $this->renderInline('<twig:Layout:ThreeColumn class="mt-4"><twig:block name="main">x</twig:block></twig:Layout:ThreeColumn>');

        self::assertMatchesRegularExpression('#<div[^>]*class="layout-three-column mt-4"#', $html);
    }

    private function renderInline(string $source): string
    {
        return $this->twig->createTemplate($source)->render();
    }
}
