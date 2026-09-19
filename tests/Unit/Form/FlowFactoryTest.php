<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Form\FlowFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * The guard this covers replaced `\assert($flow instanceof
 * FormFlowInterface)` in the assistant wizards. Those asserts never
 * executed — both container images set `zend.assertions=-1` — so a form
 * type that stopped building a flow would have surfaced as an
 * undefined-method error mid-request instead.
 */
final class FlowFactoryTest extends TestCase
{
    // Tests that a form type building a flow is returned as one.
    public function testReturnsTheFlow(): void
    {
        $flow = $this->createMock(FormFlowInterface::class);

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($flow);

        self::assertSame($flow, (new FlowFactory($formFactory))->create('SomeFlowType'));
    }

    // Ensures a form type that does not build a flow fails immediately, naming the type.
    public function testThrowsWhenTheTypeDoesNotBuildAFlow(): void
    {
        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($this->createMock(FormInterface::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SomeOrdinaryType must build a');

        (new FlowFactory($formFactory))->create('SomeOrdinaryType');
    }

    // Verifies data and options are handed to the form factory untouched.
    public function testForwardsDataAndOptions(): void
    {
        $data = new \stdClass();

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->expects(self::once())
            ->method('create')
            ->with('SomeFlowType', $data, ['data_storage' => 'slot'])
            ->willReturn($this->createMock(FormFlowInterface::class));

        (new FlowFactory($formFactory))->create('SomeFlowType', $data, ['data_storage' => 'slot']);
    }
}
