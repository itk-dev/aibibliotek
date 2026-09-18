<?php

declare(strict_types=1);

namespace App\Assistant\Format;

use App\Assistant\InvalidAssistantInputException;
use App\Assistant\Model\ModelMap;
use App\Assistant\OpenWebUiConfigSanitizer;
use App\Assistant\OpenWebUiModelNormalizer;
use App\Validator\OpenWebUiConfigValidator;
use App\Validator\ValidationResult;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * {@see FormatAdapter} for the OpenWebUI model export format.
 *
 * Delegates the format-specific work to the existing OpenWebUI
 * services — validation to {@see OpenWebUiConfigValidator}, shape
 * flattening to {@see OpenWebUiModelNormalizer}, PII stripping to
 * {@see OpenWebUiConfigSanitizer} — and owns the mapping between a
 * sanitised OWUI model dict and the neutral {@see CanonicalModel}.
 *
 * The tag priority places OpenWebUI below the more sharply-discriminating
 * JSON formats (its schema is permissive) but above the text-only Ollama
 * adapter, so {@see FormatAdapterRegistry::detect()} stays deterministic.
 */
#[AsTaggedItem(priority: 20)]
final class OpenWebUiAdapter implements FormatAdapter
{
    private const string ID = 'openwebui';
    private const string LABEL = 'Open WebUI';

    public function __construct(
        private readonly OpenWebUiConfigValidator $validator,
        private readonly OpenWebUiModelNormalizer $normalizer,
        private readonly OpenWebUiConfigSanitizer $sanitizer,
        private readonly ModelMap $modelMap,
    ) {
    }

    /**
     * OpenWebUI's schema requires only a `name`; the base model is
     * optional, so `name` is the sole field a re-importable export needs.
     */
    public function requiredCanonicalFields(): array
    {
        return ['name'];
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return self::LABEL;
    }

    public function isExperimental(): bool
    {
        return false;
    }

    public function mediaType(): string
    {
        return 'application/json';
    }

    public function fileExtension(): string
    {
        return 'json';
    }

    /**
     * An upload is OpenWebUI when it passes the OWUI validation
     * pipeline (valid JSON matching the model schema).
     */
    public function supports(string $raw): bool
    {
        return $this->validate($raw)->isValid();
    }

    public function getChecks(): array
    {
        return $this->validator->getChecks();
    }

    public function runCheck(string $name, string $raw): ValidationResult
    {
        return $this->validator->runCheck($name, $raw);
    }

    public function validate(string $raw): ValidationResult
    {
        return $this->validator->validate($raw);
    }

    /**
     * Validate, flatten, and sanitise the raw OWUI export into the
     * stored source dict.
     *
     * @return array<string, mixed> the sanitised OWUI model
     *
     * @throws InvalidAssistantInputException when validation fails
     */
    public function parseToSource(string $raw): array
    {
        $result = $this->validate($raw);
        if (!$result->isValid()) {
            throw new InvalidAssistantInputException($result->getErrors());
        }

        $decoded = json_decode($raw, associative: true, flags: \JSON_THROW_ON_ERROR);
        // Validation guarantees an accepted shape, so normalise() returns a model.
        $model = $this->normalizer->normalise($decoded);
        \assert(null !== $model);

        return $this->sanitizer->sanitize($model);
    }

    /**
     * Map a sanitised OWUI model to the canonical model.
     *
     * `description` is `meta.description`; the system prompt is
     * `params.system`; the base model prefers `base_model_id` and
     * falls back to `model`; tags come from `meta.tags`; conversation
     * starters from `meta.suggestion_prompts[].content`. The full
     * source dict is stashed under `sourceExtras['openwebui']` so a
     * same-format export re-emits everything else (id, capabilities,
     * profile image, …) untouched.
     *
     * @param array<string, mixed> $source a sanitised OWUI model
     */
    public function sourceToCanonical(array $source): CanonicalModel
    {
        $name = $source['name'] ?? null;

        return new CanonicalModel(
            name: \is_string($name) ? $name : '',
            description: $this->firstNonEmptyString($source['meta']['description'] ?? null),
            systemPrompt: $this->firstNonEmptyString($source['params']['system'] ?? null),
            baseModel: $this->firstNonEmptyString($source['base_model_id'] ?? null, $source['model'] ?? null),
            tags: $this->normaliseTags($source['meta']['tags'] ?? null),
            conversationStarters: $this->extractStarters($source['meta']['suggestion_prompts'] ?? null),
            sourceExtras: [self::ID => $source],
        );
    }

    /**
     * Rebuild an OWUI model dict from the canonical model.
     *
     * Starts from the preserved source dict (the OWUI entry in
     * `sourceExtras`, empty for a cross-format or config-less source)
     * and overlays the editable fields — `name`, `base_model_id`,
     * `meta.description`, `meta.tags` — leaving everything else
     * (params, capabilities, suggestion prompts) as stored. The
     * instance-specific model `id` is dropped so a download imports as
     * a new model; this also covers rows stored before the sanitiser
     * began stripping `id`.
     *
     * @return array<string, mixed> the OWUI model dict
     */
    public function canonicalToSource(CanonicalModel $model): array
    {
        $source = $model->sourceExtras[self::ID] ?? [];
        unset($source['id']);

        $source['name'] = $model->name;
        $source['base_model_id'] = $this->targetModel($model->baseModel);

        $meta = \is_array($source['meta'] ?? null) ? $source['meta'] : [];
        $meta['description'] = $model->description ?? '';
        $meta['tags'] = array_map(static fn (string $tag): array => ['name' => $tag], $model->tags);
        $source['meta'] = $meta;

        return $source;
    }

    /**
     * Encode an OWUI model dict as the re-importable array-of-one JSON.
     *
     * @param array<string, mixed> $source the OWUI model dict
     */
    public function serialize(array $source): string
    {
        return json_encode(
            [$source],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Resolve a canonical base-model id to the id OpenWebUI expects.
     *
     * A null model becomes an empty string; a known model is translated
     * via {@see ModelMap}; an unknown or no-equivalent model passes
     * through as its canonical name so the export still imports (the
     * importer can reassign the model inside OpenWebUI).
     *
     * @param string|null $baseModel the canonical base-model id, if any
     *
     * @return string the OpenWebUI `base_model_id` value
     */
    private function targetModel(?string $baseModel): string
    {
        if (null === $baseModel) {
            return '';
        }

        return $this->modelMap->toTarget($baseModel, self::ID) ?? $baseModel;
    }

    /**
     * Return the first argument that is a non-empty string, or null.
     */
    private function firstNonEmptyString(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (\is_string($candidate) && '' !== $candidate) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Normalise a raw `meta.tags` payload (strings or `{name}` objects)
     * to a trimmed, deduped list of names.
     *
     * @return list<string>
     */
    private function normaliseTags(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $names = [];
        foreach ($raw as $entry) {
            $name = null;
            if (\is_string($entry)) {
                $name = $entry;
            } elseif (\is_array($entry) && isset($entry['name']) && \is_string($entry['name'])) {
                $name = $entry['name'];
            }
            if (null === $name) {
                continue;
            }
            $trimmed = trim($name);
            if ('' === $trimmed) {
                continue;
            }
            $names[$trimmed] = true;
        }

        return array_keys($names);
    }

    /**
     * Pull conversation-starter texts from `meta.suggestion_prompts`.
     *
     * @return list<string>
     */
    private function extractStarters(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $starters = [];
        foreach ($raw as $entry) {
            if (\is_array($entry) && \is_string($entry['content'] ?? null) && '' !== trim($entry['content'])) {
                $starters[] = $entry['content'];
            }
        }

        return $starters;
    }
}
