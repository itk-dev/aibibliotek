<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\DevTemplateMarkerExtension;
use App\Twig\DevTemplateMarkerNodeVisitor;
use PHPUnit\Framework\TestCase;

final class DevTemplateMarkerExtensionTest extends TestCase
{
    // Tests that the extension registers the marker visitor when the kernel runs in `dev`.
    public function testRegistersVisitorInDevEnvironment(): void
    {
        $visitors = (new DevTemplateMarkerExtension('dev'))->getNodeVisitors();

        self::assertCount(1, $visitors);
        self::assertInstanceOf(DevTemplateMarkerNodeVisitor::class, $visitors[0]);
    }

    // Ensures the extension registers no visitor when the kernel runs in `prod`.
    public function testRegistersNoVisitorInProdEnvironment(): void
    {
        self::assertSame(
            [],
            (new DevTemplateMarkerExtension('prod'))->getNodeVisitors(),
        );
    }

    // Ensures the extension registers no visitor when the kernel runs in `test`.
    public function testRegistersNoVisitorInTestEnvironment(): void
    {
        self::assertSame(
            [],
            (new DevTemplateMarkerExtension('test'))->getNodeVisitors(),
        );
    }
}
