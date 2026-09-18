<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Identity-lifecycle state for a {@see \App\Entity\User}.
 *
 * Decided in ADR 006. The single source of truth for "may this credential
 * sign in?". Authorisation (roles, voters) stays orthogonal — those answer
 * "what may a signed-in user do", not "may this person sign in at all".
 */
enum UserStatus: string
{
    case AwaitingEmailConfirmation = 'awaiting_email_confirmation';
    case Pending = 'pending';
    case Approved = 'approved';
    case Blocked = 'blocked';
}
