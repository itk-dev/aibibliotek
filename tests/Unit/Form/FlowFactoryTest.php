<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Form\FlowFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\AbstractType;
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
        $flow = $this->createStub(FormFlowInterface::class);

        $formFactory = $this->createStub(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($flow);

        self::assertSame($flow, (new FlowFactory($formFactory))->create(SomeFlowType::class));
    }

    // Ensures a form type that does not build a flow fails immediately, naming the type.
    public function testThrowsWhenTheTypeDoesNotBuildAFlow(): void
    {
        $formFactory = $this->createStub(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($this->createStub(FormInterface::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SomeOrdinaryType must build a');

        (new FlowFactory($formFactory))->create(SomeOrdinaryType::class);
    }

    // Verifies data and options are handed to the form factory untouched.
    public function testForwardsDataAndOptions(): void
    {
        $data = new \stdClass();

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->expects(self::once())
            ->method('create')
            ->with(SomeFlowType::class, $data, ['data_storage' => 'slot'])
            ->willReturn($this->createStub(FormFlowInterface::class));

        (new FlowFactory($formFactory))->create(SomeFlowType::class, $data, ['data_storage' => 'slot']);
    }
}

/**
 * Stand-in for a form type that builds a flow.
 *
 * The form factory is mocked in every test here, so the type is never
 * instantiated — it only has to be a real form type for the call to
 * type-check.
 *
 * @extends AbstractType<mixed>
 */
final class SomeFlowType extends AbstractType
{
}

/**
 * Stand-in for a form type that builds an ordinary form.
 *
 * @extends AbstractType<mixed>
 */
final class SomeOrdinaryType extends AbstractType
{
}
