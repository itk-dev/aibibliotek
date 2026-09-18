<?php

declare(strict_types=1);

namespace App\Assistant\Format;

use App\Assistant\InvalidAssistantInputException;
use App\Assistant\Model\ModelMap;
use App\Validator\LibreChatConfigValidator;
use App\Validator\ValidationResult;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * {@see FormatAdapter} for the LibreChat preset format.
 *
 * Maps a preset's label (`chatGptLabel`, falling back to `modelLabel` /
 * `title`) to the canonical name, its `promptPrefix` to the system
 * prompt, and its `model` to the base model. LibreChat presets carry no
 * description, tags, or conversation starters, so those canonical fields
 * are dropped on export; everything the preset does carry (endpoint,
 * sampling params, greeting) is preserved under `sourceExtras`.
 */
#[AsTaggedItem(priority: 30)]
final class LibreChatPresetAdapter implements FormatAdapter
{
    private const string ID = 'librechat';
    private const string LABEL = 'LibreChat';

    /**
     * Functional preset fields kept when sanitising; instance-specific
     * keys (`presetId`, `user`, `_id`, timestamps) are dropped.
     */
    private const array KEEP = [
        'title', 'chatGptLabel', 'modelLabel', 'promptPrefix',
        'model', 'endpoint', 'greeting', 'temperature', 'top_p',
    ];

    public function __construct(
        private readonly LibreChatConfigValidator $validator,
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
     * A LibreChat preset needs a label to be usable, so the canonical
     * name is the field a re-importable export must carry.
     */
    public function requiredCanonicalFields(): array
    {
        return ['name'];
    }

    /**
     * Validate and reduce the raw preset to the stored, sanitised dict.
     *
     * @return array<string, mixed> the sanitised preset
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
     * Map a stored preset to the canonical model.
     *
     * The name prefers `chatGptLabel`, then `modelLabel`, then `title`;
     * the system prompt is `promptPrefix`; the base model is `model`. The
     * full preset is stashed under `sourceExtras` for a faithful
     * same-format round-trip.
     *
     * @param array<string, mixed> $source a sanitised preset
     */
    public function sourceToCanonical(array $source): CanonicalModel
    {
        return new CanonicalModel(
            name: $this->firstNonEmptyString($source['chatGptLabel'] ?? null, $source['modelLabel'] ?? null, $source['title'] ?? null) ?? '',
            systemPrompt: $this->nullableString($source['promptPrefix'] ?? null),
            baseModel: $this->nullableString($source['model'] ?? null),
            sourceExtras: [self::ID => $source],
        );
    }

    /**
     * Rebuild a LibreChat preset from the canonical model.
     *
     * Starts from the preserved preset (empty for a cross-format export),
     * translates the base model, and overlays the label and prompt. The
     * system prompt is emitted from the canonical model so a cross-format
     * export still carries it.
     *
     * @return array<string, mixed> the LibreChat preset
     */
    public function canonicalToSource(CanonicalModel $model): array
    {
        /** @var array<string, mixed> $source */
        $source = $model->sourceExtras[self::ID] ?? [];
        unset($source['presetId'], $source['_id']);

        $source['chatGptLabel'] = $model->name;
        $source['promptPrefix'] = $model->systemPrompt;
        $source['model'] = $this->targetModel($model->baseModel);

        return $source;
    }

    /**
     * Encode a preset as pretty JSON.
     *
     * @param array<string, mixed> $source the LibreChat preset
     */
    public function serialize(array $source): string
    {
        return json_encode(
            $source,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Resolve a canonical base-model id to a LibreChat-valid model id.
     *
     * A null model becomes an empty string; a known model is translated
     * via {@see ModelMap}; an unknown or no-equivalent model passes
     * through as its canonical name so the export still imports.
     *
     * @param string|null $baseModel the canonical base-model id, if any
     *
     * @return string the LibreChat `model` value
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
     * Return the value when it is a non-empty string, else null.
     */
    private function nullableString(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
