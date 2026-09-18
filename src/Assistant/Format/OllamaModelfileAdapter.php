<?php

declare(strict_types=1);

namespace App\Assistant\Format;

use App\Assistant\InvalidAssistantInputException;
use App\Assistant\Model\ModelMap;
use App\Validator\ValidationResult;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * {@see FormatAdapter} for the Ollama Modelfile format.
 *
 * Unlike the JSON formats this is a line-oriented text DSL, so it owns a
 * small parser/serialiser rather than a JSON Schema: `FROM` becomes the
 * base model, `SYSTEM` the system prompt, and `PARAMETER` / `TEMPLATE`
 * are preserved under `sourceExtras` for a faithful same-format
 * round-trip. A Modelfile carries no name, description, tags, or
 * conversation starters, so those canonical fields are dropped on export.
 *
 * Being text, it never collides with the JSON formats, so it takes the
 * lowest detection priority.
 */
#[AsTaggedItem(priority: 10)]
final class OllamaModelfileAdapter implements FormatAdapter
{
    private const string ID = 'ollama';
    private const string LABEL = 'Ollama Modelfile';
    private const string SYNTAX_CHECK = 'syntax';

    public function __construct(
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
        return 'text/plain';
    }

    public function fileExtension(): string
    {
        return 'modelfile';
    }

    public function supports(string $raw): bool
    {
        return $this->validate($raw)->isValid();
    }

    public function getChecks(): array
    {
        return [self::SYNTAX_CHECK];
    }

    public function runCheck(string $name, string $raw): ValidationResult
    {
        if (self::SYNTAX_CHECK !== $name) {
            throw new \InvalidArgumentException(\sprintf('Unknown check "%s".', $name));
        }

        return '' === ($this->parse($raw)['from'] ?? '')
            ? new ValidationResult(['A Modelfile must contain a FROM instruction.'])
            : ValidationResult::valid();
    }

    public function validate(string $raw): ValidationResult
    {
        return $this->runCheck(self::SYNTAX_CHECK, $raw);
    }

    /**
     * A Modelfile's `FROM` is mandatory, so the base model is the field a
     * re-importable export must carry.
     */
    public function requiredCanonicalFields(): array
    {
        return ['baseModel'];
    }

    /**
     * Validate and parse the Modelfile into the stored source dict.
     *
     * @return array<string, mixed> the parsed Modelfile
     *
     * @throws InvalidAssistantInputException when the Modelfile has no FROM
     */
    public function parseToSource(string $raw): array
    {
        $result = $this->validate($raw);
        if (!$result->isValid()) {
            throw new InvalidAssistantInputException($result->getErrors());
        }

        return $this->parse($raw);
    }

    /**
     * Map a parsed Modelfile to the canonical model.
     *
     * `FROM` becomes the base model and `SYSTEM` the system prompt; the
     * Modelfile carries no name/description/tags. The parsed structure is
     * stashed under `sourceExtras` so a same-format export re-emits
     * parameters and template untouched.
     *
     * @param array<string, mixed> $source a parsed Modelfile
     */
    public function sourceToCanonical(array $source): CanonicalModel
    {
        return new CanonicalModel(
            name: '',
            systemPrompt: $this->nullableString($source['system'] ?? null),
            baseModel: $this->nullableString($source['from'] ?? null),
            sourceExtras: [self::ID => $source],
        );
    }

    /**
     * Rebuild a Modelfile structure from the canonical model.
     *
     * Starts from the preserved parse (empty for a cross-format export),
     * translates the base model to an Ollama model tag, and overlays the
     * system prompt so a cross-format export still carries it.
     *
     * @return array<string, mixed> the Modelfile structure
     */
    public function canonicalToSource(CanonicalModel $model): array
    {
        /** @var array<string, mixed> $source */
        $source = $model->sourceExtras[self::ID] ?? [];

        $source['from'] = $this->targetModel($model->baseModel);
        if (null === $model->systemPrompt) {
            unset($source['system']);
        } else {
            $source['system'] = $model->systemPrompt;
        }

        return $source;
    }

    /**
     * Render a Modelfile structure to its text form.
     *
     * @param array<string, mixed> $source a Modelfile structure
     */
    public function serialize(array $source): string
    {
        $lines = [\sprintf('FROM %s', (string) ($source['from'] ?? ''))];

        if (\is_string($source['system'] ?? null) && '' !== $source['system']) {
            $lines[] = \sprintf('SYSTEM """%s"""', $source['system']);
        }

        foreach ((array) ($source['parameters'] ?? []) as $parameter) {
            if (\is_array($parameter) && 2 === \count($parameter)) {
                $lines[] = \sprintf('PARAMETER %s %s', $parameter[0], $parameter[1]);
            }
        }

        if (\is_string($source['template'] ?? null) && '' !== $source['template']) {
            $lines[] = \sprintf('TEMPLATE """%s"""', $source['template']);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Parse a Modelfile into `from` / `system` / `parameters` / `template`.
     *
     * Instruction keywords are case-insensitive; blank and `#` comment
     * lines are skipped. `SYSTEM` and `TEMPLATE` accept a bare value, a
     * double-quoted value, or a (possibly multi-line) triple-quoted value.
     * Instructions other than these four are not carried.
     *
     * @param string $raw the raw Modelfile text
     *
     * @return array<string, mixed> the parsed structure (always has `from`)
     */
    private function parse(string $raw): array
    {
        $lines = explode("\n", $raw);
        $count = \count($lines);

        $from = '';
        $system = null;
        $template = null;
        $parameters = [];

        for ($i = 0; $i < $count; ++$i) {
            $line = trim($lines[$i]);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }

            preg_match('/^(\S+)\s*(.*)$/', $line, $matches);
            $keyword = strtoupper($matches[1]);
            $rest = $matches[2];

            switch ($keyword) {
                case 'FROM':
                    $from = trim($rest);
                    break;
                case 'SYSTEM':
                    [$system, $i] = $this->readValue($rest, $lines, $i);
                    break;
                case 'TEMPLATE':
                    [$template, $i] = $this->readValue($rest, $lines, $i);
                    break;
                case 'PARAMETER':
                    $parameters[] = $this->readParameter($rest);
                    break;
            }
        }

        $parsed = ['from' => $from];
        if (null !== $system) {
            $parsed['system'] = $system;
        }
        if ([] !== $parameters) {
            $parsed['parameters'] = $parameters;
        }
        if (null !== $template) {
            $parsed['template'] = $template;
        }

        return $parsed;
    }

    /**
     * Read a `SYSTEM` / `TEMPLATE` value, consuming extra lines when the
     * value is an unterminated triple-quoted block.
     *
     * @param string       $rest  the remainder of the instruction's first line
     * @param list<string> $lines all Modelfile lines
     * @param int          $i     the current line index
     *
     * @return array{0: string, 1: int} the value and the index of the last line consumed
     */
    private function readValue(string $rest, array $lines, int $i): array
    {
        $rest = trim($rest);

        if (str_starts_with($rest, '"""')) {
            $inner = substr($rest, 3);
            $close = strpos($inner, '"""');
            if (false !== $close) {
                return [substr($inner, 0, $close), $i];
            }

            $buffer = [$inner];
            $count = \count($lines);
            while (++$i < $count) {
                $close = strpos($lines[$i], '"""');
                if (false !== $close) {
                    $buffer[] = substr($lines[$i], 0, $close);
                    break;
                }
                $buffer[] = $lines[$i];
            }

            return [trim(implode("\n", $buffer)), $i];
        }

        if (\strlen($rest) >= 2 && str_starts_with($rest, '"') && str_ends_with($rest, '"')) {
            return [substr($rest, 1, -1), $i];
        }

        return [$rest, $i];
    }

    /**
     * Split a `PARAMETER` line into its `[key, value]` pair.
     *
     * @param string $rest the remainder of the PARAMETER line
     *
     * @return array{0: string, 1: string} the key and (possibly empty) value
     */
    private function readParameter(string $rest): array
    {
        $rest = trim($rest);
        if (1 === preg_match('/^(\S+)\s+(.*)$/', $rest, $matches)) {
            return [$matches[1], trim($matches[2])];
        }

        return [$rest, ''];
    }

    /**
     * Resolve a canonical base-model id to an Ollama model tag.
     *
     * A null model becomes an empty string; a known model is translated
     * via {@see ModelMap}; an unknown or no-equivalent model passes
     * through as its canonical name so the export still parses.
     *
     * @param string|null $baseModel the canonical base-model id, if any
     *
     * @return string the Ollama `FROM` value
     */
    private function targetModel(?string $baseModel): string
    {
        if (null === $baseModel) {
            return '';
        }

        return $this->modelMap->toTarget($baseModel, self::ID) ?? $baseModel;
    }

    /**
     * Return the value when it is a non-empty string, else null.
     */
    private function nullableString(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
