<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assistant\Format;

use App\Assistant\Format\CanonicalModel;
use App\Assistant\Format\LibreChatPresetAdapter;
use App\Assistant\InvalidAssistantInputException;
use App\Assistant\Model\ModelMap;
use App\Validator\LibreChatConfigValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the LibreChat preset format adapter.
 */
final class LibreChatPresetAdapterTest extends TestCase
{
    private function adapter(): LibreChatPresetAdapter
    {
        return new LibreChatPresetAdapter(
            new LibreChatConfigValidator(\dirname(__DIR__, 4).'/config/schema/librechat-preset.json'),
            new ModelMap(\dirname(__DIR__, 4).'/config/model_map.yaml'),
        );
    }

    // Verifies the format identity accessors.
    public function testIdentity(): void
    {
        $adapter = $this->adapter();

        self::assertSame('librechat', $adapter->id());
        self::assertSame('LibreChat', $adapter->label());
        self::assertSame('application/json', $adapter->mediaType());
        self::assertSame('json', $adapter->fileExtension());
        self::assertTrue($adapter->isExperimental());
    }

    // Verifies a LibreChat preset needs the canonical name (its label).
    public function testRequiredCanonicalFields(): void
    {
        self::assertSame(['name'], $this->adapter()->requiredCanonicalFields());
    }

    // Verifies supports() recognises a preset by a preset-specific key.
    public function testSupports(): void
    {
        $adapter = $this->adapter();

        self::assertTrue($adapter->supports('{"chatGptLabel":"Demo","model":"gpt-4o"}'));
        self::assertTrue($adapter->supports('{"presetId":"p1"}'));
        self::assertFalse($adapter->supports('{"name":"Demo"}'));
        self::assertFalse($adapter->supports('{not json'));
    }

    // Verifies the check pipeline is exposed and dispatched.
    public function testChecks(): void
    {
        $adapter = $this->adapter();

        self::assertSame(['syntax', 'schema'], $adapter->getChecks());
        self::assertTrue($adapter->runCheck('syntax', '{"chatGptLabel":"Demo"}')->isValid());
        self::assertFalse($adapter->validate('{"name":"Demo"}')->isValid());
    }

    // Verifies parseToSource() validates and keeps only functional preset fields.
    public function testParseToSourceSanitises(): void
    {
        $raw = json_encode([
            'presetId' => 'p1',
            'user' => 'u1',
            '_id' => 'abc',
            'chatGptLabel' => 'Demo',
            'promptPrefix' => 'You are helpful.',
            'model' => 'gpt-4o',
            'endpoint' => 'openAI',
        ], \JSON_THROW_ON_ERROR);

        self::assertSame([
            'chatGptLabel' => 'Demo',
            'promptPrefix' => 'You are helpful.',
            'model' => 'gpt-4o',
            'endpoint' => 'openAI',
        ], $this->adapter()->parseToSource($raw));
    }

    // Ensures parseToSource() throws on a payload that fails validation.
    public function testParseToSourceThrowsOnInvalid(): void
    {
        $this->expectException(InvalidAssistantInputException::class);

        $this->adapter()->parseToSource('{"name":"Demo"}');
    }

    // Verifies sourceToCanonical() maps fields and preserves the preset under sourceExtras.
    public function testSourceToCanonicalMapsFields(): void
    {
        $source = [
            'chatGptLabel' => 'Demo',
            'promptPrefix' => 'You are helpful.',
            'model' => 'gpt-4o',
            'endpoint' => 'openAI',
        ];

        $canonical = $this->adapter()->sourceToCanonical($source);

        self::assertSame('Demo', $canonical->name);
        self::assertNull($canonical->description);
        self::assertSame('You are helpful.', $canonical->systemPrompt);
        self::assertSame('gpt-4o', $canonical->baseModel);
        self::assertSame([], $canonical->tags);
        self::assertSame(['librechat' => $source], $canonical->sourceExtras);
    }

    // Verifies the name falls back to modelLabel/title and empty branches when the preset is sparse.
    public function testSourceToCanonicalNameFallback(): void
    {
        self::assertSame('From title', $this->adapter()->sourceToCanonical(['title' => 'From title'])->name);
        self::assertSame('From modelLabel', $this->adapter()->sourceToCanonical(['modelLabel' => 'From modelLabel'])->name);

        $empty = $this->adapter()->sourceToCanonical(['endpoint' => 'openAI']);
        self::assertSame('', $empty->name);
        self::assertNull($empty->systemPrompt);
        self::assertNull($empty->baseModel);
    }

    // Verifies canonicalToSource() overlays label/prompt/model, drops preset ids, and keeps other fields.
    public function testCanonicalToSourceOverlaysEdits(): void
    {
        $stored = [
            'presetId' => 'p1',
            '_id' => 'abc',
            'chatGptLabel' => 'Original',
            'promptPrefix' => 'System prompt',
            'model' => 'gpt-4o',
            'endpoint' => 'openAI',
        ];

        $adapter = $this->adapter();
        $edited = $adapter->sourceToCanonical($stored)
            ->withEdits('Edited', 'ignored (no field)', 'gpt-4o-mini', ['ignored']);

        $rebuilt = $adapter->canonicalToSource($edited);

        self::assertArrayNotHasKey('presetId', $rebuilt);
        self::assertArrayNotHasKey('_id', $rebuilt);
        self::assertSame('Edited', $rebuilt['chatGptLabel']);
        self::assertSame('System prompt', $rebuilt['promptPrefix']);
        self::assertSame('gpt-4o-mini', $rebuilt['model']);
        self::assertSame('openAI', $rebuilt['endpoint']);
    }

    // Verifies canonicalToSource() builds a minimal preset cross-format, carrying the prompt.
    public function testCanonicalToSourceCrossFormat(): void
    {
        $rebuilt = $this->adapter()->canonicalToSource(
            new CanonicalModel(name: 'Only columns', systemPrompt: 'Be brief', baseModel: 'gpt-4o'),
        );

        self::assertSame('Only columns', $rebuilt['chatGptLabel']);
        self::assertSame('Be brief', $rebuilt['promptPrefix']);
        self::assertSame('gpt-4o', $rebuilt['model']);
    }

    // Verifies a null base model serialises to an empty model string.
    public function testCanonicalToSourceNullModel(): void
    {
        $rebuilt = $this->adapter()->canonicalToSource(new CanonicalModel(name: 'Name'));

        self::assertSame('', $rebuilt['model']);
    }

    // Verifies serialize() emits a single pretty-printed object.
    public function testSerialise(): void
    {
        $json = $this->adapter()->serialize(['chatGptLabel' => 'Demo']);

        self::assertSame(['chatGptLabel' => 'Demo'], json_decode($json, true, flags: \JSON_THROW_ON_ERROR));
    }
}
