<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SmokeTest extends KernelTestCase
{
    // Tests that the Symfony Kernel boots cleanly in the `test` environment.
    public function testKernelBoots(): void
    {
        $kernel = self::bootKernel();

        self::assertInstanceOf(Kernel::class, $kernel);
        self::assertSame('test', $kernel->getEnvironment());
    }
}
