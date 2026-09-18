<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Render-level coverage of the admin Table component family.
 *
 * Renders an inline template combining {@see Environment::createTemplate()}
 * and the six Table sub-components, then asserts on the resulting
 * HTML structure. There are no first consumers on develop yet —
 * `templates/admin/organization/list.html.twig` migrates to this
 * component once PR #109 lands.
 */
final class TableRenderTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    // Verifies the family renders a semantic <table>/<thead>/<tbody>/<th>/<td> with a horizontal-scroll wrapper.
    public function testRendersSemanticTableInsideScrollWrapper(): void
    {
        $html = $this->renderInline(<<<'TWIG'
            <twig:Table>
                <twig:Table:Head>
                    <twig:Table:Row>
                        <twig:Table:HeadCell>Name</twig:Table:HeadCell>
                        <twig:Table:HeadCell align="right">Actions</twig:Table:HeadCell>
                    </twig:Table:Row>
                </twig:Table:Head>
                <twig:Table:Body>
                    <twig:Table:Row>
                        <twig:Table:Cell>Aarhus</twig:Table:Cell>
                        <twig:Table:Cell align="right">edit</twig:Table:Cell>
                    </twig:Table:Row>
                    <twig:Table:Row>
                        <twig:Table:Cell>Aalborg</twig:Table:Cell>
                        <twig:Table:Cell align="right">edit</twig:Table:Cell>
                    </twig:Table:Row>
                </twig:Table:Body>
            </twig:Table>
            TWIG);

        // Horizontal-scroll wrapper around a semantic table.
        self::assertStringContainsString('overflow-x-auto', $html);
        self::assertMatchesRegularExpression('#<table[^>]*>.*</table>#s', $html);
        self::assertMatchesRegularExpression('#<thead[^>]*>.*</thead>#s', $html);
        self::assertMatchesRegularExpression('#<tbody[^>]*>.*</tbody>#s', $html);
        self::assertMatchesRegularExpression('#<th[^>]*scope="col"[^>]*>\s*Name\s*</th>#', $html);
        self::assertStringContainsString('Aarhus', $html);
        self::assertStringContainsString('Aalborg', $html);
    }

    // Verifies HeadCell + Cell pass `align="right"` through to `text-right` on the last column.
    public function testAlignRightAppliesTailwindRightAlignment(): void
    {
        $html = $this->renderInline(<<<'TWIG'
            <twig:Table:HeadCell align="right">Actions</twig:Table:HeadCell>
            <twig:Table:Cell align="right">edit</twig:Table:Cell>
            TWIG);

        self::assertMatchesRegularExpression('#<th[^>]*class="[^"]*text-right[^"]*"[^>]*>\s*Actions#', $html);
        self::assertMatchesRegularExpression('#<td[^>]*class="[^"]*text-right[^"]*"[^>]*>\s*edit#', $html);
    }

    // Ensures `align="center"` falls through to `text-center` on both cell types.
    public function testAlignCenterAppliesTailwindCenterAlignment(): void
    {
        $html = $this->renderInline(<<<'TWIG'
            <twig:Table:HeadCell align="center">Mid</twig:Table:HeadCell>
            <twig:Table:Cell align="center">mid</twig:Table:Cell>
            TWIG);

        self::assertMatchesRegularExpression('#<th[^>]*class="[^"]*text-center[^"]*"#', $html);
        self::assertMatchesRegularExpression('#<td[^>]*class="[^"]*text-center[^"]*"#', $html);
    }

    // Tests that omitting `align` defaults to `text-left` on both cell types.
    public function testDefaultAlignmentIsLeft(): void
    {
        $html = $this->renderInline(<<<'TWIG'
            <twig:Table:HeadCell>Name</twig:Table:HeadCell>
            <twig:Table:Cell>aarhus</twig:Table:Cell>
            TWIG);

        self::assertMatchesRegularExpression('#<th[^>]*class="[^"]*text-left[^"]*"#', $html);
        self::assertMatchesRegularExpression('#<td[^>]*class="[^"]*text-left[^"]*"#', $html);
    }

    // Verifies Body renders the zebra-striping selectors that target alternating tbody rows.
    public function testBodyAppliesZebraStripingSelector(): void
    {
        $html = $this->renderInline('<twig:Table:Body><twig:Table:Row><twig:Table:Cell>x</twig:Table:Cell></twig:Table:Row></twig:Table:Body>');

        self::assertMatchesRegularExpression('#<tbody[^>]*class="[^"]*\[&>tr:nth-child\(odd\)\]:bg-bg[^"]*"#', $html);
        self::assertMatchesRegularExpression('#<tbody[^>]*class="[^"]*\[&>tr:nth-child\(even\)\]:bg-row-alt[^"]*"#', $html);
    }

    // Ensures Table accepts a `class` prop that appends to the wrapper's defaults.
    public function testTableClassPropAppendsToWrapper(): void
    {
        $html = $this->renderInline('<twig:Table class="mt-4"><tbody></tbody></twig:Table>');

        self::assertMatchesRegularExpression('#<div[^>]*class="[^"]*overflow-x-auto[^"]*mt-4[^"]*"#', $html);
    }

    // Ensures HeadCell honours a non-default scope (so callers can switch a row's first cell to scope="row").
    public function testHeadCellRespectsCustomScope(): void
    {
        $html = $this->renderInline('<twig:Table:HeadCell scope="row">name</twig:Table:HeadCell>');

        self::assertStringContainsString('scope="row"', $html);
    }

    private function renderInline(string $source): string
    {
        return $this->twig->createTemplate($source)->render();
    }
}
