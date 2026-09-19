<?php

declare(strict_types=1);

namespace App\Twig;

use App\Assistant\Format\FormatAdapterRegistry;

/**
 * Exposes Twig filters over the {@see \App\Assistant\Format\FormatAdapter}
 * registry: `framework_label` resolves a stored format id (`openwebui`) to
 * its readable label (`Open WebUI`), and `framework_experimental` reports
 * whether that format's import/export is experimental.
 *
 * Both fall back gracefully for anything without a registered adapter, so
 * a legacy row whose format was removed still renders something meaningful.
 */
final readonly class FrameworkExtension
{
    /**
     * @param FormatAdapterRegistry $formats registry the filter delegates label lookups to
     */
    public function __construct(private FormatAdapterRegistry $formats)
    {
    }

    /**
     * Resolve a stored format id to its readable display label.
     *
     * @param string $id the stored format identifier
     *
     * @return string the human-readable label, or the id itself when no adapter is registered
     */
    #[\Twig\Attribute\AsTwigFilter(name: 'framework_label')]
    public function label(string $id): string
    {
        return $this->formats->label($id);
    }

    /**
     * Whether the format behind a stored id is experimental.
     *
     * An unregistered id is treated as non-experimental so a legacy row
     * renders without a spurious caution.
     *
     * @param string $id the stored format identifier
     *
     * @return bool true when the format is registered and experimental
     */
    #[\Twig\Attribute\AsTwigFilter(name: 'framework_experimental')]
    public function experimental(string $id): bool
    {
        return $this->formats->has($id) && $this->formats->get($id)->isExperimental();
    }
}
