<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assistant\Format;

use App\Assistant\Format\CanonicalModel;
use App\Assistant\Format\OpenAiAssistantAdapter;
use App\Assistant\InvalidAssistantInputException;
use App\Assistant\Model\ModelMap;
use App\Validator\OpenAiConfigValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the OpenAI Assistants format adapter.
 */
final class OpenAiAssistantAdapterTest extends TestCase
{
    private function adapter(): OpenAiAssistantAdapter
    {
        return new OpenAiAssistantAdapter(
            new OpenAiConfigValidator(\dirname(__DIR__, 4).'/config/schema/openai-assistant.json'),
            new ModelMap(\dirname(__DIR__, 4).'/config/model_map.yaml'),
        );
    }

    // Verifies the format identity accessors.
    public function testIdentity(): void
    {
        $adapter = $this->adapter();

        self::assertSame('openai', $adapter->id());
        self::assertSame('OpenAI Assistants', $adapter->label());
        self::assertSame('application/json', $adapter->mediaType());
        self::assertSame('json', $adapter->fileExtension());
        self::assertTrue($adapter->isExperimental());
    }

    // Verifies OpenAI needs the base model (its `model` is required, name is not).
    public function testRequiredCanonicalFields(): void
    {
        self::assertSame(['baseModel'], $this->adapter()->requiredCanonicalFields());
    }

    // Verifies supports() requires the object/model discriminators.
    public function testSupports(): void
    {
        $adapter = $this->adapter();

        self::assertTrue($adapter->supports('{"object":"assistant","model":"gpt-4o"}'));
        self::assertFalse($adapter->supports('{"model":"gpt-4o"}'));
        self::assertFalse($adapter->supports('{not json'));
    }

    // Verifies the check pipeline is exposed and dispatched.
    public function testChecks(): void
    {
        $adapter = $this->adapter();

        self::assertSame(['syntax', 'schema'], $adapter->getChecks());
        self::assertTrue($adapter->runCheck('syntax', '{"object":"assistant","model":"gpt-4o"}')->isValid());
        self::assertFalse($adapter->validate('{"model":"gpt-4o"}')->isValid());
    }

    // Verifies parseToSource() validates and keeps only the functional fields.
    public function testParseToSourceSanitises(): void
    {
        $raw = json_encode([
            'id' => 'asst_123',
            'created_at' => 1700000000,
            'object' => 'assistant',
            'model' => 'gpt-4o',
            'name' => 'Demo',
            'description' => 'A demo',
            'instructions' => 'You are helpful.',
            'metadata' => ['tags' => 'alpha, beta'],
            'tools' => [['type' => 'code_interpreter']],
        ], \JSON_THROW_ON_ERROR);

        self::assertSame([
            'model' => 'gpt-4o',
            'name' => 'Demo',
            'description' => 'A demo',
            'instructions' => 'You are helpful.',
            'metadata' => ['tags' => 'alpha, beta'],
            'tools' => [['type' => 'code_interpreter']],
        ], $this->adapter()->parseToSource($raw));
    }

    // Ensures parseToSource() throws on a payload that fails validation.
    public function testParseToSourceThrowsOnInvalid(): void
    {
        $this->expectException(InvalidAssistantInputException::class);

        $this->adapter()->parseToSource('{"model":"gpt-4o"}');
    }

    // Verifies sourceToCanonical() maps fields and splits the metadata tag CSV.
    public function testSourceToCanonicalMapsFields(): void
    {
        $source = [
            'model' => 'gpt-4o',
            'name' => 'Demo',
            'description' => 'A demo',
            'instructions' => 'You are helpful.',
            'metadata' => ['tags' => 'alpha, beta, , alpha'],
        ];

        $canonical = $this->adapter()->sourceToCanonical($source);

        self::assertSame('Demo', $canonical->name);
        self::assertSame('A demo', $canonical->description);
        self::assertSame('You are helpful.', $canonical->systemPrompt);
        self::assertSame('gpt-4o', $canonical->baseModel);
        self::assertSame(['alpha', 'beta', 'alpha'], $canonical->tags);
        self::assertSame(['openai' => $source], $canonical->sourceExtras);
    }

    // Verifies the empty/absent-field fallbacks in sourceToCanonical().
    public function testSourceToCanonicalFallbacks(): void
    {
        $canonical = $this->adapter()->sourceToCanonical(['model' => '']);

        self::assertSame('', $canonical->name);
        self::assertNull($canonical->description);
        self::assertNull($canonical->systemPrompt);
        self::assertNull($canonical->baseModel);
        self::assertSame([], $canonical->tags);
    }

    // Verifies canonicalToSource() re-asserts object, translates the model, overlays edits and tags, and drops ids.
    public function testCanonicalToSourceOverlaysEdits(): void
    {
        $stored = [
            'id' => 'asst_123',
            'created_at' => 1700000000,
            'object' => 'assistant',
            'model' => 'gpt-4o',
            'name' => 'Original',
            'instructions' => 'System prompt',
            'metadata' => ['owner' => 'team', 'tags' => 'stale'],
            'tools' => [['type' => 'code_interpreter']],
        ];

        $adapter = $this->adapter();
        $edited = $adapter->sourceToCanonical($stored)
            ->withEdits('Edited', 'Edited description', 'gpt-4o-mini', ['alpha', 'beta']);

        $rebuilt = $adapter->canonicalToSource($edited);

        self::assertArrayNotHasKey('id', $rebuilt);
        self::assertArrayNotHasKey('created_at', $rebuilt);
        self::assertSame('assistant', $rebuilt['object']);
        self::assertSame('gpt-4o-mini', $rebuilt['model']);
        self::assertSame('Edited', $rebuilt['name']);
        self::assertSame('Edited description', $rebuilt['description']);
        self::assertSame('System prompt', $rebuilt['instructions']);
        self::assertSame('alpha, beta', $rebuilt['metadata']['tags']);
        self::assertSame('team', $rebuilt['metadata']['owner']);
        self::assertSame([['type' => 'code_interpreter']], $rebuilt['tools']);
    }

    // Verifies canonicalToSource() builds a minimal object cross-format and drops an empty tag set.
    public function testCanonicalToSourceCrossFormat(): void
    {
        $model = new CanonicalModel(name: 'Only columns', systemPrompt: 'Be brief', baseModel: 'gpt-4o');

        $rebuilt = $this->adapter()->canonicalToSource($model);

        self::assertSame('assistant', $rebuilt['object']);
        self::assertSame('gpt-4o', $rebuilt['model']);
        self::assertSame('Only columns', $rebuilt['name']);
        self::assertNull($rebuilt['description']);
        self::assertSame('Be brief', $rebuilt['instructions']);
        self::assertArrayNotHasKey('tags', $rebuilt['metadata']);
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
        $adapter = $this->adapter();
        $json = $adapter->serialize(['object' => 'assistant', 'model' => 'gpt-4o']);

        self::assertSame(['object' => 'assistant', 'model' => 'gpt-4o'], json_decode($json, true, flags: \JSON_THROW_ON_ERROR));
    }
}
