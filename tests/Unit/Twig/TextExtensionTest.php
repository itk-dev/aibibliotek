<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\TextExtension;
use PHPUnit\Framework\TestCase;
use Twig\TwigFilter;

final class TextExtensionTest extends TestCase
{
    // Verifies the extension registers a single `paragraphs` filter.
    public function testGetFiltersRegistersParagraphs(): void
    {
        $extension = new TextExtension();

        $filters = $extension->getFilters();

        self::assertCount(1, $filters);
        self::assertInstanceOf(TwigFilter::class, $filters[0]);
        self::assertSame('paragraphs', $filters[0]->getName());
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
