<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Small text-shaping filters for templates.
 *
 * Currently exposes a single `paragraphs` filter that splits a
 * text blob into non-empty paragraphs on blank-line boundaries.
 * Consumers render each paragraph as a `<p>` while preserving
 * internal single line breaks via CSS `white-space: pre-wrap` —
 * the pattern the assistant details "Beskrivelse" tab uses. Moves
 * the string-handling out of Twig so templates don't need
 * double-quoted escape sequences the linter flags.
 */
final class TextExtension extends AbstractExtension
{
    /**
     * @return list<TwigFilter> the filter set this extension registers
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('paragraphs', $this->paragraphs(...)),
        ];
    }

    /**
     * Split a text blob into a list of non-empty paragraphs.
     *
     * Splits on one or more blank lines (`\r?\n\r?\n+`), trims
     * whitespace from each block, and drops empties. Internal
     * single line breaks are preserved verbatim so callers can
     * render each returned string inside a `white-space:
     * pre-wrap` element and keep the author's intent.
     *
     * @param string|null $text the text to split; null / empty yields the empty list
     *
     * @return list<string> zero or more non-empty paragraphs
     */
    public function paragraphs(?string $text): array
    {
        if (null === $text || '' === $text) {
            return [];
        }

        $blocks = preg_split('/(?:\r?\n){2,}/', $text) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $block): string => trim($block), $blocks),
            static fn (string $block): bool => '' !== $block,
        ));
    }
}
