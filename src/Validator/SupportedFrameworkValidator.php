<?php

declare(strict_types=1);

namespace App\Validator;

use App\Assistant\Format\FormatAdapterRegistry;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Delegates {@see SupportedFramework}'s check to the
 * {@see FormatAdapterRegistry} so the accepted framework ids track
 * the set of registered format adapters automatically.
 */
final class SupportedFrameworkValidator extends ConstraintValidator
{
    /**
     * @param FormatAdapterRegistry $formats the registry whose adapter ids are the accepted frameworks
     */
    public function __construct(private readonly FormatAdapterRegistry $formats)
    {
    }

    /**
     * Emit a violation when `$value` is a non-empty string that
     * isn't one of the supported machine names.
     *
     * Null / blank values pass — pair with `NotBlank` on the
     * property if the field must also be non-empty.
     *
     * @param mixed      $value      the property value under validation
     * @param Constraint $constraint the constraint instance driving the check
     *
     * @throws UnexpectedTypeException  when the constraint is not a {@see SupportedFramework}
     * @throws UnexpectedValueException when the annotated value isn't a string or null
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SupportedFramework) {
            throw new UnexpectedTypeException($constraint, SupportedFramework::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if ($this->formats->has($value)) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ value }}', $this->formatValue($value))
            ->addViolation();
    }
}
