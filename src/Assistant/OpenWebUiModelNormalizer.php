<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Reduce the several shapes an OpenWebUI model export arrives in to
 * the one flat model array the rest of the pipeline reads from.
 *
 * An export can be:
 *
 * - a top-level array holding a single model object (the shape the
 *   "export all" / Workspace download produces),
 * - a single object wrapping the model under an `info` key (the
 *   community-link / API shape), or
 * - a single flat model object (`name`, `base_model_id`, `params`,
 *   `meta`, … at the top level).
 *
 * Every consumer — metadata extraction, PII sanitising, export
 * rebuilding — works on the flat model, so this is the one place
 * that knows about the wrappers. It is deliberately forgiving:
 * anything that isn't recognisably one of the shapes yields `null`
 * rather than throwing. Shape validation (e.g. rejecting arrays with
 * more than one model) is the schema check's job, not this
 * normaliser's.
 */
final class OpenWebUiModelNormalizer
{
    /**
     * Return the single flat model array from a decoded export, or
     * null when the input isn't a recognisable model shape.
     *
     * A one-element array is unwrapped to its element; an
     * `info`-wrapped object is unwrapped to its `info` value; a flat
     * object is returned as-is. Arrays with zero or several elements
     * yield null — callers that need "exactly one" enforced with a
     * user-facing message rely on the schema check for that; this
     * method just can't produce a single model from them.
     *
     * @param mixed $parsed the JSON-decoded export (associative arrays)
     *
     * @return array<string, mixed>|null the flat model, or null when unrecognisable
     */
    public function normalise(mixed $parsed): ?array
    {
        if (\is_array($parsed) && array_is_list($parsed)) {
            if (1 !== \count($parsed)) {
                return null;
            }
            $parsed = $parsed[0];
        }

        if (!\is_array($parsed)) {
            return null;
        }

        if (isset($parsed['info']) && \is_array($parsed['info'])) {
            return $parsed['info'];
        }

        return $parsed;
    }
}
