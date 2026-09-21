<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Build a multi-step form and hand it back as a {@see FormFlowInterface}.
 *
 * `FormFactoryInterface::create()` is typed to `FormInterface`, which
 * does not expose the step cursor, `handleRequest()` semantics or
 * `reset()` that the assistant wizards rely on. Callers therefore have
 * to re-narrow the result before they can use it.
 *
 * That narrowing used to be written as
 * `\assert($flow instanceof FormFlowInterface)` at each call site. It
 * never ran: both container images set `zend.assertions=-1`, so the
 * assert is stripped at compile time and a form type that stopped
 * building a flow would surface as an undefined-method error somewhere
 * further down the request instead.
 *
 * Doing it here means the check exists once, executes for real, names
 * the offending form type when it fails, and can be tested — none of
 * which was true of the asserts it replaces.
 */
final readonly class FlowFactory
{
    /**
     * @param FormFactoryInterface $formFactory the factory the form is built with
     */
    public function __construct(
        private FormFactoryInterface $formFactory,
    ) {
    }

    /**
     * Build `$type` and return it as a flow.
     *
     * @param class-string<\Symfony\Component\Form\FormTypeInterface<mixed>> $type    the form type to build, expected to produce a flow
     * @param mixed                                                          $data    the form's underlying data
     * @param array<string, mixed>                                           $options options forwarded to the form factory
     *
     * @return FormFlowInterface the constructed flow
     *
     * @throws \LogicException when `$type` does not build a {@see FormFlowInterface},
     *                         which is a wiring mistake rather than a runtime condition
     */
    public function create(string $type, mixed $data = null, array $options = []): FormFlowInterface
    {
        $form = $this->formFactory->create($type, $data, $options);

        if (!$form instanceof FormFlowInterface) {
            throw new \LogicException(\sprintf('%s must build a %s, got %s.', $type, FormFlowInterface::class, get_debug_type($form)));
        }

        return $form;
    }
}
