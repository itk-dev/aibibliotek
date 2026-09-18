<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\DataSensitivity;
use PHPUnit\Framework\TestCase;

/**
 * Guards the {@see DataSensitivity} enum's backing values and the
 * translation-key builders.
 *
 * Persisted onto the `assistant.data_sensitivity` column as strings —
 * any rename here is a data-migration concern, so pin every case's
 * backing value with a test.
 */
final class DataSensitivityTest extends TestCase
{
    // Verifies each case's backing value stays stable so persisted rows keep round-tripping.
    public function testBackingValuesArePinned(): void
    {
        self::assertSame('no_personal', DataSensitivity::NoPersonal->value);
        self::assertSame('ordinary_personal', DataSensitivity::OrdinaryPersonal->value);
        self::assertSame('confidential', DataSensitivity::Confidential->value);
        self::assertSame('sensitive_personal', DataSensitivity::SensitivePersonal->value);
    }

    /**
     * Declaration order is the order the wizard's radio cards render in,
     * so the sequence is part of the contract, not an implementation
     * detail — least sensitive first, escalating downward.
     */
    // Verifies `::cases()` returns the four declared classifications, in map order.
    public function testCasesReturnsAllFour(): void
    {
        self::assertSame(
            [
                DataSensitivity::NoPersonal,
                DataSensitivity::OrdinaryPersonal,
                DataSensitivity::Confidential,
                DataSensitivity::SensitivePersonal,
            ],
            DataSensitivity::cases(),
        );
    }

    // Ensures example() builds the expected translation key for every case.
    public function testExampleBuildsTranslationKeys(): void
    {
        foreach (DataSensitivity::cases() as $case) {
            self::assertSame(
                'assistant.data_sensitivity.'.$case->value.'.example',
                $case->example(),
            );
        }
    }

    // Ensures label() and description() build the expected translation keys under `assistant.data_sensitivity.*`.
    public function testLabelAndDescriptionBuildTranslationKeys(): void
    {
        self::assertSame(
            'assistant.data_sensitivity.no_personal.label',
            DataSensitivity::NoPersonal->label(),
        );
        self::assertSame(
            'assistant.data_sensitivity.ordinary_personal.label',
            DataSensitivity::OrdinaryPersonal->label(),
        );
        self::assertSame(
            'assistant.data_sensitivity.ordinary_personal.description',
            DataSensitivity::OrdinaryPersonal->description(),
        );
        self::assertSame(
            'assistant.data_sensitivity.confidential.label',
            DataSensitivity::Confidential->label(),
        );
        self::assertSame(
            'assistant.data_sensitivity.sensitive_personal.description',
            DataSensitivity::SensitivePersonal->description(),
        );
    }
}
