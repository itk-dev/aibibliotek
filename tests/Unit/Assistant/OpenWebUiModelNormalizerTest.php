<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assistant;

use App\Assistant\OpenWebUiModelNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shape normaliser that reduces the several
 * OpenWebUI export shapes to a single flat model.
 */
final class OpenWebUiModelNormalizerTest extends TestCase
{
    // Verifies a one-element array is unwrapped to its model element.
    public function testUnwrapsOneElementArray(): void
    {
        $model = ['name' => 'demo', 'base_model_id' => 'gpt-4o'];

        self::assertSame($model, (new OpenWebUiModelNormalizer())->normalise([$model]));
    }

    // Verifies an `info`-wrapped object is unwrapped to its `info` value.
    public function testUnwrapsInfoWrapper(): void
    {
        $model = ['name' => 'demo'];

        self::assertSame($model, (new OpenWebUiModelNormalizer())->normalise(['info' => $model]));
    }

    // Verifies a flat model object is returned unchanged.
    public function testReturnsFlatModelAsIs(): void
    {
        $model = ['name' => 'demo', 'params' => ['system' => 'p']];

        self::assertSame($model, (new OpenWebUiModelNormalizer())->normalise($model));
    }

    // Ensures an empty array yields null — there is no single model.
    public function testEmptyArrayYieldsNull(): void
    {
        self::assertNull((new OpenWebUiModelNormalizer())->normalise([]));
    }

    // Ensures a multi-element array yields null — not exactly one model.
    public function testMultiElementArrayYieldsNull(): void
    {
        self::assertNull((new OpenWebUiModelNormalizer())->normalise([['name' => 'a'], ['name' => 'b']]));
    }

    // Ensures a one-element array whose element is not an object yields null.
    public function testOneElementScalarArrayYieldsNull(): void
    {
        self::assertNull((new OpenWebUiModelNormalizer())->normalise([42]));
    }

    // Ensures a non-array scalar yields null.
    public function testScalarYieldsNull(): void
    {
        self::assertNull((new OpenWebUiModelNormalizer())->normalise('not a model'));
    }
}
