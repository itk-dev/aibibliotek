<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assistant\Format;

use App\Assistant\Format\CanonicalModel;
use App\Assistant\Format\OllamaModelfileAdapter;
use App\Assistant\InvalidAssistantInputException;
use App\Assistant\Model\ModelMap;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Ollama Modelfile format adapter.
 */
final class OllamaModelfileAdapterTest extends TestCase
{
    private function adapter(): OllamaModelfileAdapter
    {
        return new OllamaModelfileAdapter(
            new ModelMap(\dirname(__DIR__, 4).'/config/model_map.yaml'),
        );
    }

    // Verifies the format identity accessors (text, not JSON).
    public function testIdentity(): void
    {
        $adapter = $this->adapter();

        self::assertSame('ollama', $adapter->id());
        self::assertSame('Ollama Modelfile', $adapter->label());
        self::assertSame('text/plain', $adapter->mediaType());
        self::assertSame('modelfile', $adapter->fileExtension());
        self::assertTrue($adapter->isExperimental());
    }

    // Verifies a Modelfile needs the base model (its FROM is mandatory).
    public function testRequiredCanonicalFields(): void
    {
        self::assertSame(['baseModel'], $this->adapter()->requiredCanonicalFields());
    }

    // Verifies supports() requires a FROM instruction and rejects JSON.
    public function testSupports(): void
    {
        $adapter = $this->adapter();

        self::assertTrue($adapter->supports("FROM llama3.2\nSYSTEM be nice"));
        self::assertFalse($adapter->supports('SYSTEM only'));
        self::assertFalse($adapter->supports('{"object":"assistant","model":"gpt-4o"}'));
    }

    // Verifies the single syntax check is exposed and dispatched, and rejects an unknown check.
    public function testChecks(): void
    {
        $adapter = $this->adapter();

        self::assertSame(['syntax'], $adapter->getChecks());
        self::assertTrue($adapter->runCheck('syntax', 'FROM llama3.2')->isValid());
        self::assertFalse($adapter->validate('SYSTEM only')->isValid());
    }

    // Ensures runCheck() throws for an unknown check identifier.
    public function testRunCheckRejectsUnknownCheck(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->adapter()->runCheck('schema', 'FROM llama3.2');
    }

    // Verifies parseToSource() parses FROM, a multi-line SYSTEM, PARAMETERs and TEMPLATE, skipping comments and unknown instructions.
    public function testParseToSourceParsesInstructions(): void
    {
        $modelfile = implode("\n", [
            '# a comment',
            '',
            'FROM llama3.2',
            'SYSTEM """',
            'You are helpful.',
            'Be concise.',
            '"""',
            'PARAMETER temperature 0.7',
            'PARAMETER stop',
            'TEMPLATE """{{ .Prompt }}"""',
            'LICENSE MIT',
        ]);

        self::assertSame([
            'from' => 'llama3.2',
            'system' => "You are helpful.\nBe concise.",
            'parameters' => [['temperature', '0.7'], ['stop', '']],
            'template' => '{{ .Prompt }}',
        ], $this->adapter()->parseToSource($modelfile));
    }

    // Verifies the single-line SYSTEM value forms: triple-quoted, double-quoted, and bare.
    public function testParseSystemValueForms(): void
    {
        $adapter = $this->adapter();

        self::assertSame('inline', $adapter->parseToSource("FROM x\nSYSTEM \"\"\"inline\"\"\"")['system']);
        self::assertSame('quoted', $adapter->parseToSource("FROM x\nSYSTEM \"quoted\"")['system']);
        self::assertSame('bare words', $adapter->parseToSource("FROM x\nSYSTEM bare words")['system']);
    }

    // Verifies an unterminated triple-quoted SYSTEM consumes to end of input.
    public function testParseUnterminatedTripleQuote(): void
    {
        $parsed = $this->adapter()->parseToSource("FROM x\nSYSTEM \"\"\"\nunterminated");

        self::assertSame('unterminated', $parsed['system']);
    }

    // Ensures parseToSource() throws when there is no FROM instruction.
    public function testParseToSourceThrowsWithoutFrom(): void
    {
        $this->expectException(InvalidAssistantInputException::class);

        $this->adapter()->parseToSource('SYSTEM only');
    }

    // Verifies sourceToCanonical() maps FROM/SYSTEM and preserves the parse under sourceExtras.
    public function testSourceToCanonicalMapsFields(): void
    {
        $source = ['from' => 'llama3.2', 'system' => 'You are helpful.', 'parameters' => [['temperature', '0.7']]];

        $canonical = $this->adapter()->sourceToCanonical($source);

        self::assertSame('', $canonical->name);
        self::assertNull($canonical->description);
        self::assertSame('You are helpful.', $canonical->systemPrompt);
        self::assertSame('llama3.2', $canonical->baseModel);
        self::assertSame([], $canonical->tags);
        self::assertSame(['ollama' => $source], $canonical->sourceExtras);
    }

    // Verifies canonicalToSource() translates the model, overlays the prompt, and preserves stored parameters.
    public function testCanonicalToSourceOverlaysEdits(): void
    {
        $stored = ['from' => 'llama3.1', 'system' => 'old', 'parameters' => [['temperature', '0.7']]];

        $adapter = $this->adapter();
        $edited = $adapter->sourceToCanonical($stored)
            ->withEdits('ignored', 'ignored', 'llama3.2', ['ignored']);

        $rebuilt = $adapter->canonicalToSource($edited);

        // 'llama3.2' maps to the Ollama tag 'llama3.2'; parameters survive.
        self::assertSame('llama3.2', $rebuilt['from']);
        self::assertSame('old', $rebuilt['system']);
        self::assertSame([['temperature', '0.7']], $rebuilt['parameters']);
    }

    // Verifies a null system prompt drops the SYSTEM entry.
    public function testCanonicalToSourceDropsNullSystem(): void
    {
        $rebuilt = $this->adapter()->canonicalToSource(new CanonicalModel(name: 'x', baseModel: 'llama3.2'));

        self::assertArrayNotHasKey('system', $rebuilt);
        self::assertSame('llama3.2', $rebuilt['from']);
    }

    // Verifies a model with no Ollama equivalent passes through, and a null base model yields an empty FROM.
    public function testCanonicalToSourceModelResolution(): void
    {
        $adapter = $this->adapter();

        // gpt-4o has no Ollama equivalent — the canonical name passes through.
        self::assertSame('gpt-4o', $adapter->canonicalToSource(new CanonicalModel(name: 'x', baseModel: 'gpt-4o'))['from']);
        // No base model at all yields an empty FROM.
        self::assertSame('', $adapter->canonicalToSource(new CanonicalModel(name: 'x'))['from']);
    }

    // Verifies serialize() renders FROM, SYSTEM, valid PARAMETERs (skipping malformed) and TEMPLATE.
    public function testSerialiseRendersModelfile(): void
    {
        $text = $this->adapter()->serialize([
            'from' => 'llama3.2',
            'system' => 'Be nice',
            'parameters' => [['temperature', '0.7'], ['malformed']],
            'template' => '{{ .Prompt }}',
        ]);

        self::assertSame(
            "FROM llama3.2\nSYSTEM \"\"\"Be nice\"\"\"\nPARAMETER temperature 0.7\nTEMPLATE \"\"\"{{ .Prompt }}\"\"\"\n",
            $text,
        );
    }

    // Verifies serialize() omits SYSTEM and TEMPLATE when absent.
    public function testSerialiseMinimal(): void
    {
        self::assertSame("FROM llama3.2\n", $this->adapter()->serialize(['from' => 'llama3.2']));
    }
}
