<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assistant;

use App\Assistant\Format\FormatAdapterRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies the autoconfigured detection order and end-to-end detection.
 *
 * The `#[AsTaggedItem(priority: …)]` on each adapter must place the
 * sharply-discriminating formats ahead of the permissive OpenWebUI schema
 * so {@see FormatAdapterRegistry::detect()} stays deterministic. This can
 * only be asserted against the real container, where the tagged-iterator
 * order is applied.
 */
final class FormatAdapterRegistryOrderingTest extends KernelTestCase
{
    private function registry(): FormatAdapterRegistry
    {
        self::bootKernel();
        $registry = self::getContainer()->get(FormatAdapterRegistry::class);
        self::assertInstanceOf(FormatAdapterRegistry::class, $registry);

        return $registry;
    }

    // Verifies adapters register in descending priority order.
    public function testDetectionOrderFollowsPriority(): void
    {
        self::assertSame(
            ['native', 'openai', 'librechat', 'openwebui', 'ollama'],
            array_keys($this->registry()->all()),
        );
    }

    // Verifies each format's representative payload is detected as itself.
    public function testDetectPicksTheRightAdapterPerFormat(): void
    {
        $registry = $this->registry();

        $cases = [
            'native' => '{"format":"ai-reolen","name":"Demo"}',
            'openai' => '{"object":"assistant","model":"gpt-4o"}',
            'librechat' => '{"chatGptLabel":"Demo","model":"gpt-4o"}',
            'openwebui' => '{"name":"Demo","base_model_id":"gpt-4o"}',
            'ollama' => "FROM llama3.2\nSYSTEM be nice",
        ];

        foreach ($cases as $expectedId => $payload) {
            $detected = $registry->detect($payload);
            self::assertNotNull($detected, \sprintf('No adapter detected for %s payload.', $expectedId));
            self::assertSame($expectedId, $detected->id(), \sprintf('Wrong adapter for %s payload.', $expectedId));
        }
    }
}
