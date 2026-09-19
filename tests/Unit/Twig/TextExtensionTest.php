<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\TextExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\AttributeExtension;
use Twig\Loader\ArrayLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

final class TextExtensionTest extends TestCase
{
    // Verifies the `paragraphs` filter is registered with Twig and callable from a template.
    public function testParagraphsFilterIsRegisteredWithTwig(): void
    {
        $twig = new Environment(new ArrayLoader([
            'template' => '{{ text|paragraphs|length }}',
        ]));
        $twig->addExtension(new AttributeExtension(TextExtension::class));
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            TextExtension::class => static fn (): TextExtension => new TextExtension(),
        ]));

        self::assertNotNull($twig->getFilter('paragraphs'));
        self::assertSame('2', $twig->render('template', ['text' => "first\n\nsecond"]));
    }

    // Ensures a null / empty input yields an empty list.
    public function testEmptyInputYieldsEmptyList(): void
    {
        $extension = new TextExtension();

        self::assertSame([], $extension->paragraphs(null));
        self::assertSame([], $extension->paragraphs(''));
    }

    // Verifies blank-line boundaries split the text into paragraphs.
    public function testSplitsOnBlankLineBoundaries(): void
    {
        $extension = new TextExtension();

        $text = "First para line 1\nFirst para line 2\n\nSecond para\n\n\nThird para";

        self::assertSame(
            ['First para line 1'."\n".'First para line 2', 'Second para', 'Third para'],
            $extension->paragraphs($text),
        );
    }

    // Verifies CRLF line endings are also treated as blank-line boundaries.
    public function testHandlesWindowsLineEndings(): void
    {
        $extension = new TextExtension();

        $text = "One\r\n\r\nTwo";

        self::assertSame(['One', 'Two'], $extension->paragraphs($text));
    }

    // Ensures whitespace-only blocks are dropped rather than emitted as empty paragraphs.
    public function testDropsWhitespaceOnlyBlocks(): void
    {
        $extension = new TextExtension();

        $text = "One\n\n   \n\nTwo";

        self::assertSame(['One', 'Two'], $extension->paragraphs($text));
    }

    // Verifies single-line input passes through as one paragraph.
    public function testSingleLineInputYieldsOneParagraph(): void
    {
        $extension = new TextExtension();

        self::assertSame(['just one line'], $extension->paragraphs('just one line'));
    }
}
