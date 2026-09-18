<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Assert the annotated string value is one of the format ids
 * registered in {@see \App\Assistant\Format\FormatAdapterRegistry}.
 *
 * Applied to {@see \App\Entity\Organization::$defaultFramework} so
 * fixture loads, console commands, and any future non-form write
 * paths can't leak an unknown framework into the database. The
 * admin form's own `ChoiceType` guards the UI path in parallel —
 * this constraint is the second layer.
 *
 * Blank values are ignored: pair with `NotBlank` (or make the
 * property non-nullable at the schema level) if the field must be
 * present.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class SupportedFramework extends Constraint
{
    /**
     * @var string translation key rendered when the submitted value isn't on the deploy-time list
     */
    public string $message = 'admin.organization.form.default_framework_unknown';
}
