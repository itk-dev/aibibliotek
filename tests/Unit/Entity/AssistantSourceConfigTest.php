<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Assistant;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the Assistant `source_config` accessors: the null
 * default, storing a decoded config array (fluent return), and clearing
 * a stored value with null.
 */
final class AssistantSourceConfigTest extends TestCase
{
    // Tests that a freshly-constructed Assistant has a null sourceConfig.
    public function testSourceConfigDefaultsToNull(): void
    {
        $assistant = new Assistant('t', 'd', 'lm', 'fw');

        self::assertNull($assistant->getSourceConfig());
    }

    // Verifies setSourceConfig() stores the decoded array and returns $this.
    public function testSetSourceConfigStoresAndReturnsStatic(): void
    {
        $assistant = new Assistant('t', 'd', 'lm', 'fw');
        $config = ['name' => 'demo', 'params' => ['temperature' => 0.7]];

        self::assertSame($assistant, $assistant->setSourceConfig($config));
        self::assertSame($config, $assistant->getSourceConfig());
    }

    // Ensures setSourceConfig(null) clears a previously stored value.
    public function testSetSourceConfigClearsWithNull(): void
    {
        $assistant = (new Assistant('t', 'd', 'lm', 'fw'))->setSourceConfig(['x' => 1]);

        $assistant->setSourceConfig(null);

        self::assertNull($assistant->getSourceConfig());
    }
}
