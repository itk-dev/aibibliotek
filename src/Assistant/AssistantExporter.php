<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Assistant\Format\FormatAdapterRegistry;
use App\Assistant\Model\ModelMap;
use App\Entity\Assistant;

/**
 * Builds a re-importable config download for an assistant.
 *
 * Reads the assistant's stored source (in its own format), lifts it
 * to the neutral {@see Format\CanonicalModel} with the
 * current catalogue edits applied, then renders it through the target
 * adapter. Target defaults to the assistant's own format (a faithful
 * round-trip); a different target produces a cross-format export,
 * which is lossy for fields the target can't represent.
 *
 * The rendered payload is validated against the target format before it
 * is returned, so a broken export never reaches the user. Non-blocking
 * notes — chiefly a base model that has no equivalent in the target —
 * are surfaced as warnings rather than failing the export.
 */
final class AssistantExporter
{
    /**
     * @param FormatAdapterRegistry $formats  resolves the source and target format adapters
     * @param ModelMap              $modelMap detects when a base model has no equivalent in a target
     */
    public function __construct(
        private readonly FormatAdapterRegistry $formats,
        private readonly ModelMap $modelMap,
    ) {
    }

    /**
     * Export `$assistant` to `$targetFormat` (or its own format).
     *
     * @param Assistant   $assistant    the assistant to export
     * @param string|null $targetFormat target format id, or null for the assistant's own format
     *
     * @return ExportedConfig the serialised payload plus response metadata and warnings
     *
     * @throws \InvalidArgumentException when the source or target format has no adapter
     * @throws \RuntimeException         when the rendered payload fails the target's own validation
     */
    public function export(Assistant $assistant, ?string $targetFormat = null): ExportedConfig
    {
        $sourceAdapter = $this->formats->get($assistant->getFramework());
        $targetId = $targetFormat ?? $assistant->getFramework();
        $targetAdapter = $this->formats->get($targetId);

        $canonical = $sourceAdapter
            ->sourceToCanonical($assistant->getSourceConfig() ?? [])
            ->withEdits(
                $assistant->getTitle(),
                $assistant->getDescription(),
                $assistant->getLanguageModel(),
                $this->tagNames($assistant),
            );

        $payload = $targetAdapter->serialize($targetAdapter->canonicalToSource($canonical));

        $result = $targetAdapter->validate($payload);
        if (!$result->isValid()) {
            throw new \RuntimeException(\sprintf('Export to "%s" produced an invalid payload: %s', $targetId, implode('; ', $result->getErrors())));
        }

        return new ExportedConfig(
            $payload,
            $targetAdapter->mediaType(),
            $targetAdapter->fileExtension(),
            $this->modelWarnings($canonical->baseModel, $targetId),
        );
    }

    /**
     * The export warnings for `$assistant` against every registered format.
     *
     * Lets the detail page flag, next to each target's download link, that
     * a model would need reassigning after import. Formats with no warning
     * map to an empty list.
     *
     * @param Assistant $assistant the assistant to check
     *
     * @return array<string, list<string>> format id => warnings
     */
    public function warningsByFormat(Assistant $assistant): array
    {
        $byFormat = [];
        foreach (array_keys($this->formats->all()) as $formatId) {
            $byFormat[$formatId] = $this->modelWarnings($assistant->getLanguageModel(), $formatId);
        }

        return $byFormat;
    }

    /**
     * Warnings for rendering `$baseModel` into `$formatId`.
     *
     * A known model with no equivalent in the target is the one case worth
     * flagging: the canonical name is passed through so the file still
     * imports, but the importer must point it at a real model.
     *
     * @param string|null $baseModel the canonical base-model id, if any
     * @param string      $formatId  the target format id
     *
     * @return list<string> the warnings (empty when the model maps cleanly or is absent)
     */
    private function modelWarnings(?string $baseModel, string $formatId): array
    {
        if (null === $baseModel || '' === $baseModel) {
            return [];
        }

        if ($this->modelMap->isKnown($baseModel) && null === $this->modelMap->toTarget($baseModel, $formatId)) {
            return [\sprintf(
                'The model "%s" has no equivalent in %s — set a model after importing.',
                $this->modelMap->label($baseModel) ?? $baseModel,
                $this->formats->label($formatId),
            )];
        }

        return [];
    }

    /**
     * The assistant's tag names as a plain list.
     *
     * @param Assistant $assistant the assistant to read tags from
     *
     * @return list<string> the tag names in attachment order
     */
    private function tagNames(Assistant $assistant): array
    {
        return array_values($assistant->getTags()->map(static fn ($tag): string => $tag->getName())->toArray());
    }
}
