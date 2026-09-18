<?php

declare(strict_types=1);

namespace App\Assistant\Format;

/**
 * Format-neutral representation of an assistant.
 *
 * This is the pivot every {@see FormatAdapter} converts to and from:
 * an import parses its own wire format into a `CanonicalModel`, and
 * an export turns a `CanonicalModel` back into some (possibly
 * different) wire format. The fields are the intersection worth
 * carrying across tools — a system prompt is universal, everything
 * else is present in some formats and absent in others.
 *
 * Anything a format carries that has no canonical field lives in
 * {@see self::$sourceExtras}, keyed by the id of the format that
 * produced it, so a same-format round-trip can re-inject it without
 * leaking it into a different target format.
 */
final class CanonicalModel
{
    /**
     * @param string                              $name                 human title of the assistant
     * @param string|null                         $description          long-form description, when the source carries one
     * @param string|null                         $systemPrompt         the system / instruction prompt, when present
     * @param string|null                         $baseModel            underlying model identifier (e.g. `gpt-4o`), when present
     * @param list<string>                        $tags                 catalogue tag names, deduped and trimmed
     * @param list<string>                        $conversationStarters suggested opening prompts, when the source carries them
     * @param array<string, array<string, mixed>> $sourceExtras         unmapped fields, keyed by originating format id
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $systemPrompt = null,
        public readonly ?string $baseModel = null,
        public readonly array $tags = [],
        public readonly array $conversationStarters = [],
        public readonly array $sourceExtras = [],
    ) {
    }

    /**
     * Return a copy with the catalogue-editable fields overridden.
     *
     * Export re-applies the assistant's current title / description /
     * language model / tags (the columns a curator can edit) onto the
     * model parsed from storage, so a download always reflects the
     * assistant as it now stands. The system prompt, conversation
     * starters, and per-format extras are carried over untouched.
     *
     * @param string       $name        replacement title
     * @param string|null  $description replacement description
     * @param string|null  $baseModel   replacement model identifier
     * @param list<string> $tags        replacement tag names
     *
     * @return self a new model with the four editable fields replaced
     */
    public function withEdits(string $name, ?string $description, ?string $baseModel, array $tags): self
    {
        return new self(
            name: $name,
            description: $description,
            systemPrompt: $this->systemPrompt,
            baseModel: $baseModel,
            tags: $tags,
            conversationStarters: $this->conversationStarters,
            sourceExtras: $this->sourceExtras,
        );
    }
}
