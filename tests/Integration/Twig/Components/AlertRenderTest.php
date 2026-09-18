<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Render-level coverage of the Alert component.
 *
 * Alert maps the `type` prop to both an ARIA role and a semantic
 * colour treatment (info / success / warning / danger). The tests pin
 * each type to the class triple + role so an accidental token rename
 * or role regression is caught before it ships to a flash message.
 */
final class AlertRenderTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    // Verifies the info type renders sky-tinted classes and the polite role.
    public function testInfoRendersInfoClassesAndStatusRole(): void
    {
        $html = $this->renderInline('<twig:Alert type="info">Heads up</twig:Alert>');

        self::assertMatchesRegularExpression('#<div[^>]*class="[^"]*bg-info-surface[^"]*border-info-line[^"]*text-info-ink[^"]*"#', $html);
        self::assertMatchesRegularExpression('#<div[^>]*role="status"#', $html);
        self::assertStringContainsString('Heads up', $html);
    }

    // Verifies the success type renders emerald-tinted classes and the polite role.
    public function testSuccessRendersSuccessClassesAndStatusRole(): void
    {
        $html = $this->renderInline('<twig:Alert type="success">Saved</twig:Alert>');

        self::assertMatchesRegularExpression('#<div[^>]*class="[^"]*bg-success-surface[^"]*border-success-line[^"]*text-success-ink[^"]*"#', $html);
        self::assertMatchesRegularExpression('#<div[^>]*role="status"#', $html);
    }

    // Verifies the warning type renders amber-tinted classes and the assertive role.
    public function testWarningRendersWarningClassesAndAlertRole(): void
    {
        $html = $this->renderInline('<twig:Alert type="warning">Careful</twig:Alert>');

        self::assertMatchesRegularExpression('#<div[^>]*class="[^"]*bg-warning-surface[^"]*border-warning-line[^"]*text-warning-ink[^"]*"#', $html);
        self::assertMatchesRegularExpression('#<div[^>]*role="alert"#', $html);
    }

    // Verifies the danger type renders red-tinted classes and the assertive role.
    public function testDangerRendersDangerClassesAndAlertRole(): void
    {
        $html = $this->renderInline('<twig:Alert type="danger">Broken</twig:Alert>');

        self::assertMatchesRegularExpression('#<div[^>]*class="[^"]*bg-danger-surface[^"]*border-danger-line[^"]*text-danger-ink[^"]*"#', $html);
        self::assertMatchesRegularExpression('#<div[^>]*role="alert"#', $html);
    }

    // Ensures the `class` prop appends extra utility classes onto the type classes.
    public function testExtraClassPropIsAppended(): void
    {
        $html = $this->renderInline('<twig:Alert type="info" class="mb-4">x</twig:Alert>');

        self::assertMatchesRegularExpression('#<div[^>]*class="[^"]*bg-info-surface[^"]*mb-4[^"]*"#', $html);
    }

    private function renderInline(string $source): string
    {
        return $this->twig->createTemplate($source)->render();
    }
}
