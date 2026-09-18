<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Strip an OpenWebUI model down to the portable, non-sensitive
 * fields worth persisting and re-exporting.
 *
 * A raw export carries instance-specific bookkeeping and personal
 * data — the model `id` (the source instance's primary key), the
 * uploading `user` (with email), `user_id`, `access_grants`,
 * `write_access`, activity timestamps, and a `meta.knowledge` block
 * that embeds more emails and access-control group IDs while
 * referencing knowledge collections that won't exist in any other
 * instance. None of that belongs in the catalogue database or in a
 * file meant to import cleanly elsewhere. Dropping `id` in particular
 * means a downloaded config imports as a new model rather than
 * colliding with or overwriting the model it originated from.
 *
 * This keeps an allowlist of functional fields and drops everything
 * else, so new keys added by future OpenWebUI versions are dropped
 * by default rather than leaking through. `meta.profile_image_url`
 * is additionally dropped unless it is a portable reference — an
 * absolute `http(s)` URL or a self-contained `data:` URI — so a
 * source-instance-relative path (e.g. `/user.png`) never lands in
 * the catalogue or an export where it can't resolve.
 */
final class OpenWebUiConfigSanitizer
{
    /**
     * Reduce a flat model to its portable fields.
     *
     * Operates on the flat model shape produced by
     * {@see OpenWebUiModelNormalizer}. Keeps `name`, `base_model_id`,
     * `model`, `params.system`, and the `meta.description` /
     * `meta.profile_image_url` / `meta.capabilities` /
     * `meta.suggestion_prompts` / `meta.tags` subset of `meta`; drops
     * everything else, including the instance-specific model `id`, all
     * of `meta.knowledge`, and the top-level user / access / timestamp
     * fields.
     *
     * Keys absent from the source stay absent from the result (no
     * empty placeholders are invented), so the output mirrors what
     * the export actually provided.
     *
     * @param array<string, mixed> $model the normalised flat model
     *
     * @return array<string, mixed> the model with only allowlisted fields
     */
    public function sanitize(array $model): array
    {
        $clean = $this->pick($model, ['name', 'base_model_id', 'model']);

        if (\is_array($model['params'] ?? null)) {
            $params = $this->pick($model['params'], ['system']);
            if ([] !== $params) {
                $clean['params'] = $params;
            }
        }

        if (\is_array($model['meta'] ?? null)) {
            $meta = $this->pick(
                $model['meta'],
                ['description', 'profile_image_url', 'capabilities', 'suggestion_prompts', 'tags'],
            );
            // A non-null avatar that isn't an absolute URL or data: URI
            // is a source-instance-relative path — useless elsewhere, so
            // drop it. A null (no avatar) is portable and kept.
            if (isset($meta['profile_image_url']) && !$this->isPortableImageRef($meta['profile_image_url'])) {
                unset($meta['profile_image_url']);
            }
            if ([] !== $meta) {
                $clean['meta'] = $meta;
            }
        }

        return $clean;
    }

    /**
     * Whether an avatar reference resolves outside the source instance.
     *
     * True only for an absolute `http(s)` URL or a self-contained
     * `data:` URI; a relative path (or any non-string) is not portable.
     *
     * @param mixed $value the `meta.profile_image_url` value
     *
     * @return bool true when the reference is safe to keep and re-export
     */
    private function isPortableImageRef(mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match('#^(https?://|data:)#i', $value);
    }

    /**
     * Copy the given keys from `$source` when present, preserving
     * their values (including null).
     *
     * @param array<string, mixed> $source the array to copy from
     * @param list<string>         $keys   the keys to keep, in output order
     *
     * @return array<string, mixed> the picked subset
     */
    private function pick(array $source, array $keys): array
    {
        $picked = [];
        foreach ($keys as $key) {
            if (\array_key_exists($key, $source)) {
                $picked[$key] = $source[$key];
            }
        }

        return $picked;
    }
}
