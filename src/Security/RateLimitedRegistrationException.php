<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Raised by {@see Registration::register()} when the per-IP or
 * system-wide rate limiter rejects the request.
 *
 * Distinct from the validation-level {@see RegistrationException}
 * so the controller can render HTTP 429 (Too Many Requests)
 * instead of the form's normal 422 re-render.
 *
 * Carries the same translation-key convention as its parent —
 * the message is a `messages`-domain key, not a user-facing
 * string.
 */
final class RateLimitedRegistrationException extends RegistrationException
{
}
