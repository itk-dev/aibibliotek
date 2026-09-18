<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    /**
     * The override narrows APP_ENV to a known list. Symfony's
     * Symfony\Component\DependencyInjection\Kernel\KernelTrait default
     * returns `[]`, which disables the upstream validation, so this
     * override is load-bearing: removing it silently accepts any
     * APP_ENV value.
     */
    // Tests that Kernel::getAllowedEnvs() returns the project's `prod`, `dev`, `test` whitelist.
    public function testGetAllowedEnvsReturnsProjectEnvList(): void
    {
        $kernel = new Kernel('test', false);

        $method = new \ReflectionMethod($kernel, 'getAllowedEnvs');

        self::assertSame(['prod', 'dev', 'test'], $method->invoke($kernel));
    }
}
