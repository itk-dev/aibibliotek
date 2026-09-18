<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Mail\EmailTemplateRenderer;
use League\CommonMark\CommonMarkConverter;
use PHPUnit\Framework\TestCase;

final class EmailTemplateRendererTest extends TestCase
{
    private EmailTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new EmailTemplateRenderer(new CommonMarkConverter());
    }

    // Tests the happy path: tokens substitute in both subject and body, Markdown renders to HTML, text is the post-interpolation Markdown source.
    public function testRendersSubjectAndBodyWithTokens(): void
    {
        $rendered = $this->renderer->render(
            'Hej %name%, velkommen',
            "Hej **%name%**,\n\nDin e-mail er %email%.",
            ['name' => 'Carol', 'email' => 'carol@example.test'],
        );

        self::assertSame('Hej Carol, velkommen', $rendered->subject);
        self::assertStringContainsString('<strong>Carol</strong>', $rendered->bodyHtml);
        self::assertStringContainsString('carol@example.test', $rendered->bodyHtml);
        self::assertStringContainsString('**Carol**', $rendered->bodyText);
        self::assertStringContainsString('carol@example.test', $rendered->bodyText);
    }

    // Ensures unknown tokens are left untouched so missing replacements are visible to the recipient.
    public function testLeavesUnknownTokensInPlace(): void
    {
        $rendered = $this->renderer->render(
            'Plain subject',
            'Hi %missing%',
            ['unrelated' => 'x'],
        );

        self::assertSame('Plain subject', $rendered->subject);
        self::assertStringContainsString('%missing%', $rendered->bodyText);
    }

    // Verifies an empty token map renders the template verbatim (early-return path on substitute()).
    public function testEmptyTokenMapRendersTemplatesVerbatim(): void
    {
        $rendered = $this->renderer->render('Subject %name%', 'Body %name%', []);

        self::assertSame('Subject %name%', $rendered->subject);
        self::assertStringContainsString('%name%', $rendered->bodyText);
    }
}
