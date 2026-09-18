<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Validator\ValidationResult;
use PHPUnit\Framework\TestCase;

final class ValidationResultTest extends TestCase
{
    // Tests that a result built with no errors reports isValid() and an empty error list.
    public function testDefaultConstructorYieldsValid(): void
    {
        $result = new ValidationResult();

        self::assertTrue($result->isValid());
        self::assertSame([], $result->getErrors());
    }

    // Verifies ValidationResult::valid() returns an empty, valid result.
    public function testValidFactoryReturnsValid(): void
    {
        $result = ValidationResult::valid();

        self::assertTrue($result->isValid());
        self::assertSame([], $result->getErrors());
    }

    // Ensures a result with errors reports isValid() === false and preserves the error list.
    public function testErrorListIsExposedAndMarksInvalid(): void
    {
        $result = new ValidationResult(['malformed JSON', 'missing key']);

        self::assertFalse($result->isValid());
        self::assertSame(['malformed JSON', 'missing key'], $result->getErrors());
    }
}
