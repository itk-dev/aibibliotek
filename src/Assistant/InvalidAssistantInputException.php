<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Thrown when submitted assistant input can't be accepted — the
 * requested format id has no adapter, or the config fails the
 * selected format's validation pipeline.
 *
 * Carries the localised error messages so the controller can render
 * them on the form.
 */
final class InvalidAssistantInputException extends \DomainException
{
    /**
     * @param list<string> $errors the validator's per-check error messages
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Assistant input failed validation.');
    }

    /**
     * @return list<string> the validator's per-check error messages
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
