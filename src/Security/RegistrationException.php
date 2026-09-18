<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Raised by {@see Registration::register()} when the self-signup
 * pipeline rejects the submitted input.
 *
 * The exception message carries a translation key in the `messages`
 * domain (e.g. `register.error.invalid_email`), not a user-facing
 * string. The controller renders it through `|trans` so the error
 * stays localised.
 */
class RegistrationException extends \RuntimeException
{
}
