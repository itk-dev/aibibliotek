<?php

declare(strict_types=1);

namespace App\Validator;

/**
 * Outcome of running a format adapter's config validation.
 *
 * Immutable value object. A result with an empty error list is
 * considered valid; any error string makes the result invalid. The
 * shared validator service composes per-check results into a single
 * result by concatenating their errors.
 */
final class ValidationResult
{
    /**
     * @param list<string> $errors human-readable error messages, one per failed assertion
     */
    public function __construct(private readonly array $errors = [])
    {
    }

    /**
     * Convenience constructor for the no-errors case.
     *
     * @return self a result with an empty error list
     */
    public static function valid(): self
    {
        return new self([]);
    }

    /**
     * Whether the validated value passes every assertion.
     *
     * @return bool true when the error list is empty
     */
    public function isValid(): bool
    {
        return [] === $this->errors;
    }

    /**
     * @return list<string> the raw error messages, in insertion order
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
