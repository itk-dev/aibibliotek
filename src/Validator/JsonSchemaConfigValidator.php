<?php

declare(strict_types=1);

namespace App\Validator;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator as SchemaValidator;

/**
 * Reusable syntax + JSON Schema validation pipeline for a config blob.
 *
 * The two checks — `syntax` (the payload is valid JSON) and `schema`
 * (it matches a JSON Schema document) — are format-agnostic: only the
 * schema document differs between formats. A concrete subclass supplies
 * the schema path (typically via an autowired constructor argument) and
 * inherits the whole pipeline, so every JSON import/export format gets
 * the same progressive validation without duplicating it.
 *
 * Each entry in {@see self::CHECKS} maps to a `validate*` method below.
 * The same list drives:
 *
 * - the AJAX upload UI (one HTTP call per check so the progress bar can
 *   step forward one notch as each check returns), and
 * - the synchronous {@see self::validate()} used by the form submit
 *   handler.
 *
 * New checks are added by adding a method and a list entry; the progress
 * bar's "denominator" follows automatically.
 */
abstract class JsonSchemaConfigValidator
{
    /**
     * Ordered list of check identifiers exposed to the client.
     *
     * The order is significant: it's the order the AJAX UI steps
     * through and the order {@see self::validate()} aggregates errors
     * in. Syntax runs first so a parse failure surfaces before the
     * structural schema check runs against garbage.
     */
    private const array CHECKS = ['syntax', 'schema'];

    /**
     * Decoded JSON Schema document, loaded lazily from
     * {@see self::$schemaPath} and cached for the service's lifetime.
     */
    private ?object $schema = null;

    /**
     * @param string $schemaPath absolute path to the JSON Schema this format validates against
     */
    public function __construct(
        protected readonly string $schemaPath,
    ) {
    }

    /**
     * @return list<string> the check identifiers in declared order
     */
    public function getChecks(): array
    {
        return self::CHECKS;
    }

    /**
     * Run a single named check.
     *
     * @param string $name one of the identifiers from {@see self::getChecks()}
     * @param string $json raw uploaded payload
     *
     * @return ValidationResult the result of that check
     *
     * @throws \InvalidArgumentException when `$name` is not a registered check
     */
    public function runCheck(string $name, string $json): ValidationResult
    {
        return match ($name) {
            'syntax' => $this->validateSyntax($json),
            'schema' => $this->validateSchema($json),
            default => throw new \InvalidArgumentException(\sprintf('Unknown check "%s".', $name)),
        };
    }

    /**
     * Assert that `$json` is syntactically valid JSON.
     *
     * Uses `JSON_THROW_ON_ERROR` so the underlying decoder's own
     * message surfaces to the caller (line / character offsets are
     * useful when the upload UI displays the error).
     *
     * @param string $json raw uploaded payload
     *
     * @return ValidationResult valid when `$json` parses, otherwise
     *                          a single error carrying the decoder
     *                          message
     */
    public function validateSyntax(string $json): ValidationResult
    {
        try {
            json_decode($json, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new ValidationResult([$e->getMessage()]);
        }

        return ValidationResult::valid();
    }

    /**
     * Assert that `$json` matches the configured JSON Schema.
     *
     * The payload is decoded into objects (not associative arrays)
     * because {@see SchemaValidator} works on the PHP object shape
     * JSON Schema is defined against. A parse failure is reported as
     * a schema error too, so the check is safe to run standalone (the
     * AJAX UI runs it after `syntax`, but nothing guarantees a caller
     * ran `syntax` first).
     *
     * @param string $json raw uploaded payload
     *
     * @return ValidationResult valid when the payload matches the
     *                          schema, otherwise the flattened schema
     *                          error messages
     */
    public function validateSchema(string $json): ValidationResult
    {
        try {
            $data = json_decode($json, associative: false, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new ValidationResult([$e->getMessage()]);
        }

        $result = (new SchemaValidator())->validate($data, $this->schema());

        if ($result->isValid()) {
            return ValidationResult::valid();
        }

        // An `anyOf` at the schema root makes opis emit the same
        // generic "must match type" line once per failed branch; dedupe
        // so the UI shows each distinct reason once. `error()` is
        // non-null once the result is invalid.
        $error = $result->error();

        return new ValidationResult(
            null === $error ? [] : array_values(array_unique((new ErrorFormatter())->formatFlat($error))),
        );
    }

    /**
     * Run every registered check in order and aggregate errors.
     *
     * Used by the form-submit path which doesn't need per-check
     * progress feedback. The AJAX upload path calls
     * {@see self::runCheck()} per check instead.
     *
     * @param string $json raw uploaded payload
     *
     * @return ValidationResult valid when every check passes, else
     *                          the concatenated errors from each
     *                          failing check
     */
    public function validate(string $json): ValidationResult
    {
        $errors = [];
        foreach (self::CHECKS as $check) {
            $errors = [...$errors, ...$this->runCheck($check, $json)->getErrors()];
        }

        return new ValidationResult($errors);
    }

    /**
     * Load and cache the decoded JSON Schema document.
     *
     * Read once from disk and memoised so repeated checks within a
     * request (the AJAX UI fires one HTTP call per check) don't
     * re-read and re-parse the file.
     *
     * @return object the decoded schema, as the object shape opis expects
     *
     * @throws \RuntimeException when the schema file cannot be read or parsed
     */
    private function schema(): object
    {
        if (null !== $this->schema) {
            return $this->schema;
        }

        $contents = is_file($this->schemaPath) ? file_get_contents($this->schemaPath) : false;
        if (false === $contents) {
            throw new \RuntimeException(\sprintf('Unable to read JSON Schema at "%s".', $this->schemaPath));
        }

        $decoded = json_decode($contents, associative: false, flags: \JSON_THROW_ON_ERROR);
        if (!\is_object($decoded)) {
            throw new \RuntimeException(\sprintf('JSON Schema at "%s" did not decode to an object.', $this->schemaPath));
        }

        return $this->schema = $decoded;
    }
}
