<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Assistant\Format\FormatAdapterRegistry;
use App\Assistant\Format\NativeAdapter;
use App\Assistant\Format\OpenWebUiAdapter;
use App\Assistant\Model\ModelMap;
use App\Assistant\OpenWebUiConfigSanitizer;
use App\Assistant\OpenWebUiModelNormalizer;
use App\Twig\FrameworkExtension;
use App\Validator\NativeConfigValidator;
use App\Validator\OpenWebUiConfigValidator;
use PHPUnit\Framework\TestCase;
use Twig\TwigFilter;

final class FrameworkExtensionTest extends TestCase
{
    private function extension(): FrameworkExtension
    {
        $root = \dirname(__DIR__, 3);

        return new FrameworkExtension(new FormatAdapterRegistry([
            new OpenWebUiAdapter(
                new OpenWebUiConfigValidator($root.'/config/schema/openwebui-model.json'),
                new OpenWebUiModelNormalizer(),
                new OpenWebUiConfigSanitizer(),
                new ModelMap($root.'/config/model_map.yaml'),
            ),
            new NativeAdapter(new NativeConfigValidator($root.'/config/schema/native-assistant.json')),
        ]));
    }

    // Verifies the extension registers the framework filters.
    public function testGetFiltersRegistersFrameworkFilters(): void
    {
        $names = array_map(static fn (TwigFilter $f): string => $f->getName(), $this->extension()->getFilters());

        self::assertContains('framework_label', $names);
        self::assertContains('framework_experimental', $names);
    }

    // Tests that the filter resolves a registered format id to its adapter label.
    public function testLabelResolvesRegisteredFormat(): void
    {
        self::assertSame('Open WebUI', $this->extension()->label('openwebui'));
    }

    // Ensures the filter falls back to the id for legacy / unregistered values.
    public function testLabelFallsBackToIdForUnknown(): void
    {
        self::assertSame('legacy_thing', $this->extension()->label('legacy_thing'));
    }

    // Verifies experimental() reflects the adapter flag and is false for unknown ids.
    public function testExperimentalReflectsAdapterFlag(): void
    {
        $extension = $this->extension();

        self::assertFalse($extension->experimental('openwebui'));
        self::assertTrue($extension->experimental('native'));
        self::assertFalse($extension->experimental('legacy_thing'));
    }
}
