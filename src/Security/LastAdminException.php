<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Thrown when a role mutation would remove the last remaining
 * `ROLE_ADMIN` from the site, locking everyone out of the admin
 * surface.
 *
 * Caught by the role-change controller and surfaced as HTTP 409
 * (Conflict) to the JSON client. The request is well-formed and the
 * actor is authorised — the *resource state* refuses the transition.
 */
final class LastAdminException extends \DomainException
{
}
