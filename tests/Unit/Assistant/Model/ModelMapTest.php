<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assistant\Model;

use App\Assistant\Model\ModelMap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for the cross-system base-model translation map.
 *
 * Behaviour is exercised against a small controlled map written to a temp
 * file; the last test asserts the integrity of the real shipped map so a
 * typo there fails CI.
 */
final class ModelMapTest extends TestCase
{
    /**
     * Absolute path to the real shipped model map.
     */
    private function shippedMapPath(): string
    {
        return \dirname(__DIR__, 4).'/config/model_map.yaml';
    }

    /**
     * Build a ModelMap backed by a temp file holding the given YAML.
     *
     * The temp file is registered for teardown via the returned path being
     * created under the system temp dir; tests unlink it themselves.
     */
    private function mapFrom(string $yaml): ModelMap
    {
        $path = tempnam(sys_get_temp_dir(), 'model-map');
        self::assertIsString($path);
        file_put_contents($path, $yaml);

        return new ModelMap($path);
    }

    /**
     * A small, fully-featured controlled map: aliases, a null target, a
     * model with no aliases, and a model with no label (falls back to id).
     */
    private function controlledYaml(): string
    {
        return <<<'YAML'
            fallback: passthrough
            models:
              alpha:
                label: Alpha Model
                aliases:
                  - alpha-1
                  - ALPHA/one
                targets:
                  openai: alpha-openai
                  ollama: null
              beta:
                label: Beta Model
                targets:
                  openai: beta-openai
              gamma:
                targets:
                  openai: gamma-openai
            YAML;
    }

    // Verifies normalise() folds canonical ids and aliases (case-insensitively) onto the canonical id.
    public function testNormaliseFoldsIdsAndAliases(): void
    {
        $map = $this->mapFrom($this->controlledYaml());

        self::assertSame('alpha', $map->normalise('alpha'));
        self::assertSame('alpha', $map->normalise('alpha-1'));
        self::assertSame('alpha', $map->normalise('  ALPHA/ONE '));
        self::assertNull($map->normalise('nope'));
    }

    // Verifies isKnown() reflects whether the model resolves.
    public function testIsKnownReflectsResolution(): void
    {
        $map = $this->mapFrom($this->controlledYaml());

        self::assertTrue($map->isKnown('alpha-1'));
        self::assertFalse($map->isKnown('nope'));
    }

    // Verifies toTarget() resolves a known model to its target id.
    public function testToTargetResolvesKnownModel(): void
    {
        $map = $this->mapFrom($this->controlledYaml());

        self::assertSame('alpha-openai', $map->toTarget('alpha-1', 'openai'));
    }

    // Verifies toTarget() returns null when a known model has no equivalent (explicit null or missing target).
    public function testToTargetReturnsNullWhenNoEquivalent(): void
    {
        $map = $this->mapFrom($this->controlledYaml());

        self::assertNull($map->toTarget('alpha', 'ollama'));
        self::assertNull($map->toTarget('alpha', 'librechat'));
    }

    // Verifies toTarget() passes an unknown model through verbatim (fallback policy).
    public function testToTargetPassesUnknownThrough(): void
    {
        $map = $this->mapFrom($this->controlledYaml());

        self::assertSame('some-custom-model', $map->toTarget('some-custom-model', 'openai'));
    }

    // Verifies choices() returns label => id, falling back to the id as label when none is set.
    public function testChoicesReturnsLabelToId(): void
    {
        $map = $this->mapFrom($this->controlledYaml());

        self::assertSame(
            ['Alpha Model' => 'alpha', 'Beta Model' => 'beta', 'gamma' => 'gamma'],
            $map->choices(),
        );
    }

    // Verifies label() returns the label for a known id and null for an unknown one.
    public function testLabelResolvesKnownIdOnly(): void
    {
        $map = $this->mapFrom($this->controlledYaml());

        self::assertSame('Alpha Model', $map->label('alpha'));
        self::assertNull($map->label('nope'));
    }

    // Verifies aliasesFor() lists declared aliases in map order and returns an empty list for aliasless / unknown ids.
    public function testAliasesForReturnsDeclaredAliasesOnly(): void
    {
        $map = $this->mapFrom($this->controlledYaml());

        self::assertSame(['alpha-1', 'ALPHA/one'], $map->aliasesFor('alpha'));
        self::assertSame([], $map->aliasesFor('beta'));
        self::assertSame([], $map->aliasesFor('nope'));
    }

    // Ensures a map that does not decode to a mapping surfaces as a RuntimeException.
    public function testLoadRejectsNonMappingRoot(): void
    {
        $map = $this->mapFrom('just a scalar');

        $this->expectException(\RuntimeException::class);
        $map->choices();
    }

    // Ensures an unsupported fallback strategy surfaces as a RuntimeException.
    public function testLoadRejectsUnsupportedFallback(): void
    {
        $map = $this->mapFrom("fallback: drop\nmodels:\n  x:\n    label: X");

        $this->expectException(\RuntimeException::class);
        $map->choices();
    }

    // Ensures a missing/invalid models mapping surfaces as a RuntimeException.
    public function testLoadRejectsMissingModels(): void
    {
        $map = $this->mapFrom('fallback: passthrough');

        $this->expectException(\RuntimeException::class);
        $map->choices();
    }

    // Ensures the shipped map loads and translates a real model across systems.
    public function testShippedMapTranslatesAcrossSystems(): void
    {
        $map = new ModelMap($this->shippedMapPath());

        self::assertNotEmpty($map->choices());
        self::assertSame('gpt-4o', $map->normalise('gpt-4o-2024-08-06'));
        self::assertSame('gpt-4o', $map->toTarget('gpt-4o', 'openwebui'));
        // GPT-4o has no local Ollama equivalent — resolves to null, not a swap.
        self::assertNull($map->toTarget('gpt-4o', 'ollama'));
    }

    // Ensures the shipped map has unique aliases, present labels, and string|null targets.
    public function testShippedMapIntegrity(): void
    {
        $parsed = Yaml::parseFile($this->shippedMapPath());
        self::assertIsArray($parsed['models'] ?? null);

        $seen = [];
        foreach ($parsed['models'] as $id => $definition) {
            self::assertIsString($definition['label'] ?? null, \sprintf('Model "%s" needs a label.', $id));
            self::assertNotSame('', $definition['label']);

            foreach ([$id, ...($definition['aliases'] ?? [])] as $spelling) {
                $key = strtolower(trim((string) $spelling));
                self::assertArrayNotHasKey($key, $seen, \sprintf('Duplicate alias/id "%s".', $spelling));
                $seen[$key] = true;
            }

            foreach ($definition['targets'] ?? [] as $targetId) {
                self::assertTrue(null === $targetId || \is_string($targetId));
            }
        }
    }
}
