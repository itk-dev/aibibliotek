<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assistant\Format;

use App\Assistant\Format\CanonicalModel;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the format-neutral canonical model DTO.
 */
final class CanonicalModelTest extends TestCase
{
    // Verifies the constructor exposes every field, defaulting the optional ones.
    public function testConstructorStoresFields(): void
    {
        $model = new CanonicalModel(
            name: 'Demo',
            description: 'A demo',
            systemPrompt: 'You are helpful.',
            baseModel: 'gpt-4o',
            tags: ['alpha', 'beta'],
            conversationStarters: ['Hi'],
            sourceExtras: ['openwebui' => ['capabilities' => ['vision' => false]]],
        );

        self::assertSame('Demo', $model->name);
        self::assertSame('A demo', $model->description);
        self::assertSame('You are helpful.', $model->systemPrompt);
        self::assertSame('gpt-4o', $model->baseModel);
        self::assertSame(['alpha', 'beta'], $model->tags);
        self::assertSame(['Hi'], $model->conversationStarters);
        self::assertSame(['openwebui' => ['capabilities' => ['vision' => false]]], $model->sourceExtras);
    }

    // Verifies optional fields default to null / empty when omitted.
    public function testOptionalFieldsDefault(): void
    {
        $model = new CanonicalModel(name: 'Only name');

        self::assertNull($model->description);
        self::assertNull($model->systemPrompt);
        self::assertNull($model->baseModel);
        self::assertSame([], $model->tags);
        self::assertSame([], $model->conversationStarters);
        self::assertSame([], $model->sourceExtras);
    }

    // Verifies withEdits() overrides the four editable fields and preserves the rest.
    public function testWithEditsReplacesEditableFieldsAndKeepsTheRest(): void
    {
        $original = new CanonicalModel(
            name: 'Old',
            description: 'Old description',
            systemPrompt: 'Prompt',
            baseModel: 'gpt-4o',
            tags: ['old'],
            conversationStarters: ['Start'],
            sourceExtras: ['openwebui' => ['x' => 1]],
        );

        $edited = $original->withEdits('New', 'New description', 'gpt-4o-mini', ['new']);

        self::assertSame('New', $edited->name);
        self::assertSame('New description', $edited->description);
        self::assertSame('gpt-4o-mini', $edited->baseModel);
        self::assertSame(['new'], $edited->tags);
        // Preserved from the original.
        self::assertSame('Prompt', $edited->systemPrompt);
        self::assertSame(['Start'], $edited->conversationStarters);
        self::assertSame(['openwebui' => ['x' => 1]], $edited->sourceExtras);
        // Original is untouched.
        self::assertSame('Old', $original->name);
    }
}
