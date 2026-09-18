<?php

declare(strict_types=1);

namespace App\Assistant\Format;

use App\Assistant\InvalidAssistantInputException;
use App\Assistant\Model\ModelMap;
use App\Validator\OpenAiConfigValidator;
use App\Validator\ValidationResult;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * {@see FormatAdapter} for the OpenAI Assistants API object.
 *
 * Maps the OpenAI object's `name` / `description` / `instructions` /
 * `model` onto the canonical fields, carries catalogue tags in the
 * assistant `metadata` as a comma-separated `tags` entry, and preserves
 * everything else (tools, sampling params) under `sourceExtras` for a
 * faithful same-format round-trip. The base model is translated to an
 * OpenAI-valid id via {@see ModelMap} on export.
 *
 * The `object: assistant` const is a sharp discriminator, so this format
 * sits just below the native envelope in detection priority.
 */
#[AsTaggedItem(priority: 40)]
final class OpenAiAssistantAdapter implements FormatAdapter
{
    private const string ID = 'openai';
    private const string LABEL = 'OpenAI Assistants';

    /**
     * Functional fields kept when sanitising; instance/time-specific keys
     * (`id`, `created_at`) and the re-derivable `object` are dropped.
     */
    private const array KEEP = [
        'model', 'name', 'description', 'instructions',
        'metadata', 'tools', 'temperature', 'top_p', 'response_format',
    ];

    public function __construct(
        private readonly OpenAiConfigValidator $validator,
        private readonly ModelMap $modelMap,
    ) {
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
        return true;
    }

    public function mediaType(): string
    {
        return 'application/json';
    }

    public function fileExtension(): string
    {
        return 'json';
    }

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
     * OpenAI requires a `model`; the assistant `name` is optional, so the
     * base model is the field a re-importable export must carry.
     */
    public function requiredCanonicalFields(): array
    {
        return ['baseModel'];
    }

    /**
     * Validate and reduce the raw object to the stored, sanitised dict.
     *
     * @return array<string, mixed> the sanitised assistant object
     *
     * @throws InvalidAssistantInputException when validation fails
     */
    public function parseToSource(string $raw): array
    {
        $result = $this->validate($raw);
        if (!$result->isValid()) {
            throw new InvalidAssistantInputException($result->getErrors());
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, associative: true, flags: \JSON_THROW_ON_ERROR);

        $clean = [];
        foreach (self::KEEP as $key) {
            if (\array_key_exists($key, $decoded)) {
                $clean[$key] = $decoded[$key];
            }
        }

        return $clean;
    }

    /**
     * Map a stored OpenAI object to the canonical model.
     *
     * `instructions` becomes the system prompt, `model` the base model,
     * and a comma-separated `metadata.tags` becomes the tag list. The full
     * source is stashed under `sourceExtras` so a same-format export
     * re-emits tools and sampling params untouched.
     *
     * @param array<string, mixed> $source a sanitised assistant object
     */
    public function sourceToCanonical(array $source): CanonicalModel
    {
        $name = $source['name'] ?? null;
        $model = $source['model'] ?? null;

        return new CanonicalModel(
            name: \is_string($name) ? $name : '',
            description: $this->nullableString($source['description'] ?? null),
            systemPrompt: $this->nullableString($source['instructions'] ?? null),
            baseModel: \is_string($model) && '' !== $model ? $model : null,
            tags: $this->tagsFromMetadata($source['metadata'] ?? null),
            sourceExtras: [self::ID => $source],
        );
    }

    /**
     * Rebuild an OpenAI object from the canonical model.
     *
     * Starts from the preserved source (empty for a cross-format export),
     * re-asserts `object`, translates the base model, and overlays the
     * editable fields. The system prompt is emitted from the canonical
     * model so a cross-format export still carries it. Catalogue tags are
     * written back as a comma-separated `metadata.tags`.
     *
     * @return array<string, mixed> the OpenAI object
     */
    public function canonicalToSource(CanonicalModel $model): array
    {
        /** @var array<string, mixed> $source */
        $source = $model->sourceExtras[self::ID] ?? [];
        unset($source['id'], $source['created_at']);

        $source['object'] = 'assistant';
        $source['model'] = $this->targetModel($model->baseModel);
        $source['name'] = $model->name;
        $source['description'] = $model->description;
        $source['instructions'] = $model->systemPrompt;

        $metadata = \is_array($source['metadata'] ?? null) ? $source['metadata'] : [];
        if ([] !== $model->tags) {
            $metadata['tags'] = implode(', ', $model->tags);
        } else {
            unset($metadata['tags']);
        }
        $source['metadata'] = $metadata;

        return $source;
    }

    /**
     * Encode an OpenAI object as pretty JSON.
     *
     * @param array<string, mixed> $source the OpenAI object
     */
    public function serialize(array $source): string
    {
        return json_encode(
            $source,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Resolve a canonical base-model id to an OpenAI-valid model id.
     *
     * A null model becomes an empty string; a known model is translated
     * via {@see ModelMap}; an unknown or no-equivalent model passes
     * through as its canonical name so the export still imports.
     *
     * @param string|null $baseModel the canonical base-model id, if any
     *
     * @return string the OpenAI `model` value
     */
    private function targetModel(?string $baseModel): string
    {
        if (null === $baseModel) {
            return '';
        }

        return $this->modelMap->toTarget($baseModel, self::ID) ?? $baseModel;
    }

    /**
     * Split a comma-separated `metadata.tags` value into a tag list.
     *
     * @param mixed $metadata the assistant `metadata` value
     *
     * @return list<string> the trimmed, non-empty tag names
     */
    private function tagsFromMetadata(mixed $metadata): array
    {
        if (!\is_array($metadata) || !\is_string($metadata['tags'] ?? null)) {
            return [];
        }

        $tags = [];
        foreach (explode(',', $metadata['tags']) as $tag) {
            $trimmed = trim($tag);
            if ('' !== $trimmed) {
                $tags[] = $trimmed;
            }
        }

        return $tags;
    }

    /**
     * Return the value when it is a non-empty string, else null.
     */
    private function nullableString(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
