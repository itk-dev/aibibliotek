<?php

declare(strict_types=1);

namespace App\Assistant\Format;

use App\Assistant\InvalidAssistantInputException;
use App\Validator\NativeConfigValidator;
use App\Validator\ValidationResult;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * {@see FormatAdapter} for the AI-reolen native format.
 *
 * The native format is the catalogue's own lossless interchange shape: a
 * thin `format: ai-reolen` envelope carrying the canonical fields
 * verbatim. Because it maps one-to-one onto {@see CanonicalModel} it is
 * the only target that survives a cross-format export without dropping a
 * field, and its `format` const makes detection unambiguous — hence the
 * highest detection priority.
 */
#[AsTaggedItem(priority: 50)]
final class NativeAdapter implements FormatAdapter
{
    private const string ID = 'native';
    private const string LABEL = 'AI-reolen';
    private const int VERSION = 1;

    /**
     * The value written to (and required in) the `format` envelope field.
     */
    private const string FORMAT_TOKEN = 'ai-reolen';

    public function __construct(
        private readonly NativeConfigValidator $validator,
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
     * The native format carries every canonical field, so only the
     * always-required `name` is structurally needed.
     */
    public function requiredCanonicalFields(): array
    {
        return ['name'];
    }

    /**
     * Validate the payload and reduce it to the stored canonical-field dict.
     *
     * Native payloads carry no PII or instance data, so "sanitising" here
     * is just coercing each field to its canonical type and dropping the
     * envelope (`format` / `version`), which is re-added on serialise.
     *
     * @return array<string, mixed> the stored source dict
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

        return $this->fields($decoded);
    }

    /**
     * Map a stored native dict to the canonical model.
     *
     * The mapping is an identity: the stored dict already holds the
     * canonical fields, so nothing is parked in `sourceExtras`.
     *
     * @param array<string, mixed> $source a stored native dict
     */
    public function sourceToCanonical(array $source): CanonicalModel
    {
        $fields = $this->fields($source);

        return new CanonicalModel(
            name: $fields['name'],
            description: $fields['description'],
            systemPrompt: $fields['systemPrompt'],
            baseModel: $fields['baseModel'],
            tags: $fields['tags'],
            conversationStarters: $fields['conversationStarters'],
        );
    }

    /**
     * Render a canonical model into the native wire envelope.
     *
     * Every field is emitted as-is — including the base model, which the
     * native format carries as the neutral canonical id rather than any
     * system-specific name.
     *
     * @return array<string, mixed> the native wire dict
     */
    public function canonicalToSource(CanonicalModel $model): array
    {
        return [
            'format' => self::FORMAT_TOKEN,
            'version' => self::VERSION,
            'name' => $model->name,
            'description' => $model->description,
            'systemPrompt' => $model->systemPrompt,
            'baseModel' => $model->baseModel,
            'tags' => $model->tags,
            'conversationStarters' => $model->conversationStarters,
        ];
    }

    /**
     * Encode a native wire dict as pretty JSON.
     *
     * @param array<string, mixed> $source the native wire dict
     */
    public function serialize(array $source): string
    {
        return json_encode(
            $source,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Coerce a raw dict to the canonical field set with typed defaults.
     *
     * @param array<string, mixed> $data a decoded native payload or stored dict
     *
     * @return array{name: string, description: ?string, systemPrompt: ?string, baseModel: ?string, tags: list<string>, conversationStarters: list<string>}
     */
    private function fields(array $data): array
    {
        return [
            'name' => \is_string($data['name'] ?? null) ? $data['name'] : '',
            'description' => $this->nullableString($data['description'] ?? null),
            'systemPrompt' => $this->nullableString($data['systemPrompt'] ?? null),
            'baseModel' => $this->nullableString($data['baseModel'] ?? null),
            'tags' => $this->stringList($data['tags'] ?? null),
            'conversationStarters' => $this->stringList($data['conversationStarters'] ?? null),
        ];
    }

    /**
     * Return the value when it is a non-empty string, else null.
     */
    private function nullableString(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Reduce a raw value to a list of non-empty strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }
}
