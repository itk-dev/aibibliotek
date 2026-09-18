<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assistant;

use App\Assistant\InvalidAssistantInputException;
use PHPUnit\Framework\TestCase;

final class InvalidAssistantInputExceptionTest extends TestCase
{
    // Verifies the exception preserves the validator's error list.
    public function testCarriesTheValidatorErrorList(): void
    {
        $exception = new InvalidAssistantInputException(['malformed JSON', 'missing key']);

        self::assertSame(['malformed JSON', 'missing key'], $exception->getErrors());
        self::assertSame('Assistant input failed validation.', $exception->getMessage());
    }
}
