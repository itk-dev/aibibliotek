<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assistant\Format;

use App\Assistant\Format\CanonicalModel;
use App\Assistant\Format\NativeAdapter;
use App\Assistant\InvalidAssistantInputException;
use App\Validator\NativeConfigValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the AI-reolen native format adapter.
 */
final class NativeAdapterTest extends TestCase
{
    private function adapter(): NativeAdapter
    {
        return new NativeAdapter(
            new NativeConfigValidator(\dirname(__DIR__, 4).'/config/schema/native-assistant.json'),
        );
    }

    // Verifies the format identity accessors.
    public function testIdentity(): void
    {
        $adapter = $this->adapter();

        self::assertSame('native', $adapter->id());
        self::assertSame('AI-reolen', $adapter->label());
        self::assertSame('application/json', $adapter->mediaType());
        self::assertSame('json', $adapter->fileExtension());
        self::assertTrue($adapter->isExperimental());
    }

    // Verifies the native format needs only the canonical name.
    public function testRequiredCanonicalFields(): void
    {
        self::assertSame(['name'], $this->adapter()->requiredCanonicalFields());
    }

    // Verifies supports() requires the format envelope discriminator.
    public function testSupports(): void
    {
        $adapter = $this->adapter();

        self::assertTrue($adapter->supports('{"format":"ai-reolen","name":"Demo"}'));
        self::assertFalse($adapter->supports('{"name":"Demo"}'));
        self::assertFalse($adapter->supports('{not json'));
    }

    // Verifies the check pipeline is exposed and dispatched.
    public function testChecks(): void
    {
        $adapter = $this->adapter();

        self::assertSame(['syntax', 'schema'], $adapter->getChecks());
        self::assertTrue($adapter->runCheck('syntax', '{"format":"ai-reolen","name":"Demo"}')->isValid());
        self::assertFalse($adapter->validate('{"name":"Demo"}')->isValid());
    }

    // Verifies parseToSource() coerces fields to canonical types and drops the envelope.
    public function testParseToSourceCoercesFields(): void
    {
        $raw = json_encode([
            'format' => 'ai-reolen',
            'version' => 1,
            'name' => 'Demo',
            'description' => 'A demo',
            'systemPrompt' => 'You are helpful.',
            'baseModel' => 'gpt-4o',
            'tags' => ['alpha', '  ', 'beta'],
            'conversationStarters' => ['Hi'],
        ], \JSON_THROW_ON_ERROR);

        self::assertSame([
            'name' => 'Demo',
            'description' => 'A demo',
            'systemPrompt' => 'You are helpful.',
            'baseModel' => 'gpt-4o',
            'tags' => ['alpha', 'beta'],
            'conversationStarters' => ['Hi'],
        ], $this->adapter()->parseToSource($raw));
    }

    // Ensures parseToSource() throws on a payload missing the envelope.
    public function testParseToSourceThrowsOnInvalid(): void
    {
        $this->expectException(InvalidAssistantInputException::class);

        $this->adapter()->parseToSource('{"name":"Demo"}');
    }

    // Verifies sourceToCanonical() maps the stored dict, defaulting absent/typed-wrong fields.
    public function testSourceToCanonicalMapsFields(): void
    {
        $canonical = $this->adapter()->sourceToCanonical([
            'name' => 'Demo',
            'description' => 'A demo',
            'systemPrompt' => 'Prompt',
            'baseModel' => 'gpt-4o',
            'tags' => ['alpha'],
            'conversationStarters' => ['Hi'],
        ]);

        self::assertSame('Demo', $canonical->name);
        self::assertSame('A demo', $canonical->description);
        self::assertSame('Prompt', $canonical->systemPrompt);
        self::assertSame('gpt-4o', $canonical->baseModel);
        self::assertSame(['alpha'], $canonical->tags);
        self::assertSame(['Hi'], $canonical->conversationStarters);
        self::assertSame([], $canonical->sourceExtras);
    }

    // Verifies sourceToCanonical() defaults an empty/typed-wrong source.
    public function testSourceToCanonicalDefaults(): void
    {
        $canonical = $this->adapter()->sourceToCanonical(['name' => 42, 'tags' => 'not-a-list']);

        self::assertSame('', $canonical->name);
        self::assertNull($canonical->description);
        self::assertNull($canonical->baseModel);
        self::assertSame([], $canonical->tags);
    }

    // Verifies canonicalToSource() emits the envelope and serialize() round-trips losslessly.
    public function testCanonicalToSourceAndSerialiseRoundTrip(): void
    {
        $adapter = $this->adapter();
        $model = new CanonicalModel(
            name: 'Demo',
            description: 'A demo',
            systemPrompt: 'Prompt',
            baseModel: 'gpt-4o',
            tags: ['alpha', 'beta'],
            conversationStarters: ['Hi'],
        );

        $wire = $adapter->canonicalToSource($model);

        self::assertSame([
            'format' => 'ai-reolen',
            'version' => 1,
            'name' => 'Demo',
            'description' => 'A demo',
            'systemPrompt' => 'Prompt',
            'baseModel' => 'gpt-4o',
            'tags' => ['alpha', 'beta'],
            'conversationStarters' => ['Hi'],
        ], $wire);

        // The serialised payload re-parses to the same canonical fields.
        $reparsed = $adapter->parseToSource($adapter->serialize($wire));
        self::assertSame($adapter->sourceToCanonical($reparsed)->name, $model->name);
        self::assertSame($adapter->sourceToCanonical($reparsed)->baseModel, $model->baseModel);
        self::assertSame($adapter->sourceToCanonical($reparsed)->tags, $model->tags);
    }
}
