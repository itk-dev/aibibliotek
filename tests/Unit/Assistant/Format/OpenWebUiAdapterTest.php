<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assistant\Format;

use App\Assistant\Format\CanonicalModel;
use App\Assistant\Format\OpenWebUiAdapter;
use App\Assistant\InvalidAssistantInputException;
use App\Assistant\Model\ModelMap;
use App\Assistant\OpenWebUiConfigSanitizer;
use App\Assistant\OpenWebUiModelNormalizer;
use App\Validator\OpenWebUiConfigValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the OpenWebUI format adapter.
 */
final class OpenWebUiAdapterTest extends TestCase
{
    private function adapter(): OpenWebUiAdapter
    {
        return new OpenWebUiAdapter(
            new OpenWebUiConfigValidator(\dirname(__DIR__, 4).'/config/schema/openwebui-model.json'),
            new OpenWebUiModelNormalizer(),
            new OpenWebUiConfigSanitizer(),
            new ModelMap(\dirname(__DIR__, 4).'/config/model_map.yaml'),
        );
    }

    // Verifies the format identity accessors.
    public function testIdentity(): void
    {
        $adapter = $this->adapter();

        self::assertSame('openwebui', $adapter->id());
        self::assertSame('Open WebUI', $adapter->label());
        self::assertSame('application/json', $adapter->mediaType());
        self::assertSame('json', $adapter->fileExtension());
        self::assertFalse($adapter->isExperimental());
    }

    // Verifies OpenWebUI requires only the canonical name for a re-importable export.
    public function testRequiredCanonicalFields(): void
    {
        self::assertSame(['name'], $this->adapter()->requiredCanonicalFields());
    }

    // Verifies supports() accepts a valid OWUI payload and rejects non-OWUI input.
    public function testSupports(): void
    {
        $adapter = $this->adapter();

        self::assertTrue($adapter->supports('{"name":"demo"}'));
        self::assertFalse($adapter->supports('{not json'));
        self::assertFalse($adapter->supports('[{"base_model_id":"gpt-4o"}]'));
    }

    // Verifies the check pipeline is exposed and dispatched.
    public function testChecks(): void
    {
        $adapter = $this->adapter();

        self::assertSame(['syntax', 'schema'], $adapter->getChecks());
        self::assertTrue($adapter->runCheck('syntax', '{"name":"demo"}')->isValid());
        self::assertFalse($adapter->validate('{"base_model_id":"gpt-4o"}')->isValid());
    }

    // Verifies parseToSource() validates, unwraps, and strips PII to the stored source dict.
    public function testParseToSourceSanitises(): void
    {
        $raw = json_encode([[
            'id' => 'demo',
            'user_id' => 'redacted',
            'name' => 'Demo',
            'base_model_id' => 'gpt-4o',
            'params' => ['system' => 'prompt', 'temperature' => 0.5],
            'meta' => ['description' => 'd', 'tags' => [['name' => 'alpha']], 'knowledge' => [['email' => 'a@b.dk']]],
            'user' => ['email' => 'a@b.dk'],
        ]], \JSON_THROW_ON_ERROR);

        self::assertSame([
            'name' => 'Demo',
            'base_model_id' => 'gpt-4o',
            'params' => ['system' => 'prompt'],
            'meta' => ['description' => 'd', 'tags' => [['name' => 'alpha']]],
        ], $this->adapter()->parseToSource($raw));
    }

    // Ensures parseToSource() throws on a payload that fails validation.
    public function testParseToSourceThrowsOnInvalid(): void
    {
        $this->expectException(InvalidAssistantInputException::class);

        $this->adapter()->parseToSource('[{"base_model_id":"gpt-4o"}]');
    }

    // Verifies sourceToCanonical() maps every field and preserves the source under sourceExtras.
    public function testSourceToCanonicalMapsFields(): void
    {
        $source = [
            'name' => 'Demo',
            'base_model_id' => 'gpt-4o',
            'params' => ['system' => 'You are helpful.'],
            'meta' => [
                'description' => 'A demo',
                'tags' => [['name' => 'alpha'], 'beta', ['other' => 'skip'], '  ', 'alpha'],
                'suggestion_prompts' => [['content' => 'Hi'], ['content' => '  '], 'not-an-object'],
            ],
        ];

        $canonical = $this->adapter()->sourceToCanonical($source);

        self::assertSame('Demo', $canonical->name);
        self::assertSame('A demo', $canonical->description);
        self::assertSame('You are helpful.', $canonical->systemPrompt);
        self::assertSame('gpt-4o', $canonical->baseModel);
        self::assertSame(['alpha', 'beta'], $canonical->tags);
        self::assertSame(['Hi'], $canonical->conversationStarters);
        self::assertSame(['openwebui' => $source], $canonical->sourceExtras);
    }

    // Verifies the fallbacks and empty branches when optional keys are absent or wrong-typed.
    public function testSourceToCanonicalFallbacksAndEmpties(): void
    {
        // No name, no meta/params; base model only via the legacy `model` key.
        $canonical = $this->adapter()->sourceToCanonical(['model' => 'llama3.1:70b']);

        self::assertSame('', $canonical->name);
        self::assertNull($canonical->description);
        self::assertNull($canonical->systemPrompt);
        self::assertSame('llama3.1:70b', $canonical->baseModel);
        self::assertSame([], $canonical->tags);
        self::assertSame([], $canonical->conversationStarters);
    }

    // Verifies canonicalToSource() overlays edits on the preserved source, drops the instance id, and serialize() wraps array-of-one.
    public function testCanonicalToSourceAndSerialiseRoundTrip(): void
    {
        $stored = [
            'id' => 'demo',
            'name' => 'Original',
            'base_model_id' => 'gpt-4o',
            'params' => ['system' => 'System prompt'],
            'meta' => [
                'description' => 'Original',
                'capabilities' => ['vision' => false],
                'tags' => [['name' => 'stale']],
            ],
        ];

        $adapter = $this->adapter();
        $edited = $adapter->sourceToCanonical($stored)
            ->withEdits('Edited title', 'Edited description', 'gpt-4o-mini', ['alpha', 'beta']);

        $rebuilt = $adapter->canonicalToSource($edited);

        self::assertSame([
            'name' => 'Edited title',
            'base_model_id' => 'gpt-4o-mini',
            'params' => ['system' => 'System prompt'],
            'meta' => [
                'description' => 'Edited description',
                'capabilities' => ['vision' => false],
                'tags' => [['name' => 'alpha'], ['name' => 'beta']],
            ],
        ], $rebuilt);

        $decoded = json_decode($adapter->serialize($rebuilt), associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([$rebuilt], $decoded);
    }

    // Verifies canonicalToSource() builds a minimal model when there is no preserved source (config-less / cross-format).
    public function testCanonicalToSourceWithoutPreservedSource(): void
    {
        $model = new CanonicalModel(name: 'Only columns', description: 'A description', baseModel: 'gpt-4o', tags: ['x']);

        self::assertSame([
            'name' => 'Only columns',
            'base_model_id' => 'gpt-4o',
            'meta' => ['description' => 'A description', 'tags' => [['name' => 'x']]],
        ], $this->adapter()->canonicalToSource($model));
    }

    // Verifies canonicalToSource() null-coalesces a null base model and description to empty strings.
    public function testCanonicalToSourceNullEditableFields(): void
    {
        $rebuilt = $this->adapter()->canonicalToSource(new CanonicalModel(name: 'Name'));

        self::assertSame('', $rebuilt['base_model_id']);
        self::assertSame('', $rebuilt['meta']['description']);
        self::assertSame([], $rebuilt['meta']['tags']);
    }
}
