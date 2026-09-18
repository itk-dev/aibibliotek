<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assistant\Format;

use App\Assistant\Format\CanonicalModel;
use App\Assistant\Format\FormatAdapter;
use App\Assistant\Format\FormatAdapterRegistry;
use App\Validator\ValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the format-adapter registry.
 */
final class FormatAdapterRegistryTest extends TestCase
{
    // Verifies get() returns the adapter registered under an id.
    public function testGetReturnsAdapterById(): void
    {
        $owui = $this->stub('openwebui', 'Open WebUI');
        $registry = new FormatAdapterRegistry([$owui]);

        self::assertSame($owui, $registry->get('openwebui'));
    }

    // Ensures get() throws for an unregistered id.
    public function testGetThrowsForUnknownId(): void
    {
        $registry = new FormatAdapterRegistry([$this->stub('openwebui', 'Open WebUI')]);

        $this->expectException(\InvalidArgumentException::class);

        $registry->get('nope');
    }

    // Verifies detect() returns the first adapter whose supports() matches, in order.
    public function testDetectReturnsFirstSupportingAdapter(): void
    {
        $json = $this->stub('openwebui', 'Open WebUI', supports: static fn (string $raw): bool => str_starts_with($raw, '{'));
        $text = $this->stub('other', 'Other', supports: static fn (string $raw): bool => true);
        $registry = new FormatAdapterRegistry([$json, $text]);

        self::assertSame($json, $registry->detect('{"name":"x"}'));
        self::assertSame($text, $registry->detect('plain text'));
    }

    // Ensures detect() returns null when no adapter recognises the payload.
    public function testDetectReturnsNullWhenNoneMatch(): void
    {
        $registry = new FormatAdapterRegistry([
            $this->stub('openwebui', 'Open WebUI', supports: static fn (string $raw): bool => false),
        ]);

        self::assertNull($registry->detect('anything'));
    }

    // Verifies has() reflects registration.
    public function testHasReflectsRegistration(): void
    {
        $registry = new FormatAdapterRegistry([$this->stub('openwebui', 'Open WebUI')]);

        self::assertTrue($registry->has('openwebui'));
        self::assertFalse($registry->has('other'));
    }

    // Verifies all() maps id to label in registration order.
    public function testAllMapsIdToLabel(): void
    {
        $registry = new FormatAdapterRegistry([
            $this->stub('openwebui', 'Open WebUI'),
            $this->stub('other', 'Other'),
        ]);

        self::assertSame(['openwebui' => 'Open WebUI', 'other' => 'Other'], $registry->all());
    }

    // Verifies label() resolves a known id and falls back to the id for unknown.
    public function testLabelResolvesKnownAndFallsBack(): void
    {
        $registry = new FormatAdapterRegistry([$this->stub('openwebui', 'Open WebUI')]);

        self::assertSame('Open WebUI', $registry->label('openwebui'));
        self::assertSame('legacy-format', $registry->label('legacy-format'));
    }

    // Verifies default() is the first registered id.
    public function testDefaultReturnsFirstId(): void
    {
        $registry = new FormatAdapterRegistry([
            $this->stub('openwebui', 'Open WebUI'),
            $this->stub('other', 'Other'),
        ]);

        self::assertSame('openwebui', $registry->default());
    }

    // Verifies default() is an empty string when no adapter is registered.
    public function testDefaultIsEmptyWhenNoAdapters(): void
    {
        self::assertSame('', (new FormatAdapterRegistry([]))->default());
    }

    // Verifies requiredForAnyExport() unions each adapter's required fields, deduped.
    public function testRequiredForAnyExportUnionsFields(): void
    {
        $registry = new FormatAdapterRegistry([
            $this->stub('a', 'A', required: ['name']),
            $this->stub('b', 'B', required: ['name', 'baseModel']),
        ]);

        self::assertSame(['name', 'baseModel'], $registry->requiredForAnyExport());
    }

    /**
     * Build a minimal {@see FormatAdapter} stub for registry tests.
     *
     * Only id / label / supports / requiredCanonicalFields are exercised
     * here; the conversion methods return trivial values since the
     * registry never calls them.
     *
     * @param callable(string):bool|null $supports optional supports() behaviour
     * @param list<string>               $required required canonical fields
     */
    private function stub(string $id, string $label, ?callable $supports = null, array $required = []): FormatAdapter
    {
        return new class($id, $label, $supports, $required) implements FormatAdapter {
            /**
             * @param callable(string):bool|null $supports
             * @param list<string>               $required
             */
            public function __construct(
                private readonly string $id,
                private readonly string $label,
                private $supports,
                private readonly array $required = [],
            ) {
            }

            public function requiredCanonicalFields(): array
            {
                return $this->required;
            }

            public function id(): string
            {
                return $this->id;
            }

            public function label(): string
            {
                return $this->label;
            }

            public function isExperimental(): bool
            {
                return false;
            }

            public function mediaType(): string
            {
                return 'application/json';
            }

            public function fileExtension(): string
            {
                return 'json';
            }

            public function supports(string $raw): bool
            {
                return null !== $this->supports && ($this->supports)($raw);
            }

            public function getChecks(): array
            {
                return [];
            }

            public function runCheck(string $name, string $raw): ValidationResult
            {
                return ValidationResult::valid();
            }

            public function validate(string $raw): ValidationResult
            {
                return ValidationResult::valid();
            }

            public function parseToSource(string $raw): array
            {
                return [];
            }

            public function sourceToCanonical(array $source): CanonicalModel
            {
                return new CanonicalModel(name: '');
            }

            public function canonicalToSource(CanonicalModel $model): array
            {
                return [];
            }

            public function serialize(array $source): string
            {
                return '';
            }
        };
    }
}
