<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assistant;

use App\Assistant\AssistantExporter;
use App\Assistant\Format\FormatAdapterRegistry;
use App\Assistant\Format\OllamaModelfileAdapter;
use App\Assistant\Format\OpenAiAssistantAdapter;
use App\Assistant\Format\OpenWebUiAdapter;
use App\Assistant\Model\ModelMap;
use App\Assistant\OpenWebUiConfigSanitizer;
use App\Assistant\OpenWebUiModelNormalizer;
use App\Entity\Assistant;
use App\Entity\Tag;
use App\Validator\OpenAiConfigValidator;
use App\Validator\OpenWebUiConfigValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the assistant export assembler.
 */
final class AssistantExporterTest extends TestCase
{
    private function modelMap(): ModelMap
    {
        return new ModelMap(\dirname(__DIR__, 3).'/config/model_map.yaml');
    }

    private function exporter(): AssistantExporter
    {
        $modelMap = $this->modelMap();
        $schema = static fn (string $name): string => \dirname(__DIR__, 3).'/config/schema/'.$name;

        $registry = new FormatAdapterRegistry([
            new OpenWebUiAdapter(
                new OpenWebUiConfigValidator($schema('openwebui-model.json')),
                new OpenWebUiModelNormalizer(),
                new OpenWebUiConfigSanitizer(),
                $modelMap,
            ),
            new OpenAiAssistantAdapter(new OpenAiConfigValidator($schema('openai-assistant.json')), $modelMap),
            new OllamaModelfileAdapter($modelMap),
        ]);

        return new AssistantExporter($registry, $modelMap);
    }

    // Verifies export() renders the stored source with the entity's edits applied, drops the instance id, plus response metadata.
    public function testExportAppliesEntityEditsToStoredSource(): void
    {
        $assistant = new Assistant('Title', 'Desc', 'gpt-4o-mini', 'openwebui', [new Tag('a'), new Tag('b')]);
        $assistant->setSourceConfig([
            'id' => 'demo',
            'name' => 'Original',
            'base_model_id' => 'gpt-4o',
            'params' => ['system' => 'System prompt'],
            'meta' => ['description' => 'Original', 'capabilities' => ['vision' => false], 'tags' => [['name' => 'stale']]],
        ]);

        $exported = $this->exporter()->export($assistant);

        self::assertSame('application/json', $exported->mediaType);
        self::assertSame('json', $exported->extension);

        $payload = json_decode($exported->payload, associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([[
            'name' => 'Title',
            'base_model_id' => 'gpt-4o-mini',
            'params' => ['system' => 'System prompt'],
            'meta' => [
                'description' => 'Desc',
                'capabilities' => ['vision' => false],
                'tags' => [['name' => 'a'], ['name' => 'b']],
            ],
        ]], $payload);
    }

    // Verifies a config-less assistant still exports a valid minimal model built from its columns.
    public function testExportConfiglessAssistant(): void
    {
        $assistant = new Assistant('Only columns', 'A description', 'gpt-4o', 'openwebui');

        $exported = $this->exporter()->export($assistant, 'openwebui');

        $payload = json_decode($exported->payload, associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([[
            'name' => 'Only columns',
            'base_model_id' => 'gpt-4o',
            'meta' => ['description' => 'A description', 'tags' => []],
        ]], $payload);
        self::assertSame([], $exported->warnings);
    }

    // Verifies a cross-format export warns (non-blocking) when the model has no equivalent in the target.
    public function testCrossFormatExportWarnsOnMissingModelEquivalent(): void
    {
        $assistant = new Assistant('Title', 'Desc', 'gpt-4o', 'openwebui');

        $exported = $this->exporter()->export($assistant, 'ollama');

        self::assertSame('text/plain', $exported->mediaType);
        self::assertStringContainsString('FROM gpt-4o', $exported->payload);
        self::assertCount(1, $exported->warnings);
        self::assertStringContainsString('no equivalent in Ollama Modelfile', $exported->warnings[0]);
    }

    // Verifies export() throws when the rendered payload fails the target's own validation.
    public function testExportThrowsWhenTargetPayloadInvalid(): void
    {
        // OpenAI requires a non-empty model; an assistant with no language model yields an invalid payload.
        $assistant = new Assistant('Title', 'Desc', '', 'openwebui');

        $this->expectException(\RuntimeException::class);

        $this->exporter()->export($assistant, 'openai');
    }

    // Verifies warningsByFormat() flags only the targets with no equivalent for the assistant's model.
    public function testWarningsByFormatFlagsMissingEquivalents(): void
    {
        $assistant = new Assistant('Title', 'Desc', 'gpt-4o', 'openwebui');

        $byFormat = $this->exporter()->warningsByFormat($assistant);

        self::assertSame([], $byFormat['openwebui']);
        self::assertSame([], $byFormat['openai']);
        self::assertCount(1, $byFormat['ollama']);
    }

    // Verifies an assistant with no language model produces no model warnings anywhere.
    public function testWarningsByFormatIsEmptyWithoutModel(): void
    {
        $assistant = new Assistant('Title', 'Desc', '', 'openwebui');

        foreach ($this->exporter()->warningsByFormat($assistant) as $warnings) {
            self::assertSame([], $warnings);
        }
    }
}
