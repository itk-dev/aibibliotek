<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Security\EmailDomain;
use App\Security\Roles;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorises a `User` action on a target {@see User}, scoped by email
 * domain and gated by an explicit "manager cannot touch admin"
 * guard.
 *
 * Two families of attributes are supported:
 *
 * - Status mutation: `MANAGE_USER`, `APPROVE_USER`, `BLOCK_USER`.
 * - Role mutation: `PROMOTE_TO_MANAGER`, `PROMOTE_TO_ADMIN`,
 *   `DEMOTE` (remove all permissions).
 *
 * Common rules (every attribute):
 *
 * 1. The acting user must hold {@see Roles::DOMAIN_MANAGER}.
 * 2. A manager (actor without `ROLE_ADMIN`) may never act on a
 *    subject that holds `ROLE_ADMIN`, regardless of email domain.
 *    Blocking, approving, or demoting an admin all count as
 *    "acting on" — letting a manager block an admin would lock
 *    that admin out of the install even though their role list is
 *    intact, which is the same privilege-escalation shape as
 *    demoting them.
 * 3. If the actor holds `ROLE_ADMIN`, allow across all domains.
 * 4. Otherwise allow iff the actor and subject share the lowercased
 *    email domain returned by {@see EmailDomain::of()}.
 *
 * Role-mutation extras:
 *
 * - `PROMOTE_TO_ADMIN` is admin-only: a manager never gets to mint an
 *   admin, regardless of domain.
 *
 * The voter never reads the subject's `status` — identity state
 * (signed-in or not) is handled by the `UserCheckerInterface`.
 * Authorisation only answers "what may this signed-in actor do to
 * that target?".
 */
final class ManageUserVoter extends Voter
{
    public const string MANAGE = 'MANAGE_USER';
    public const string APPROVE = 'APPROVE_USER';
    public const string BLOCK = 'BLOCK_USER';
    public const string PROMOTE_TO_MANAGER = 'PROMOTE_TO_MANAGER';
    public const string PROMOTE_TO_ADMIN = 'PROMOTE_TO_ADMIN';
    public const string DEMOTE = 'DEMOTE_USER';

    private const array SUPPORTED = [
        self::MANAGE,
        self::APPROVE,
        self::BLOCK,
        self::PROMOTE_TO_MANAGER,
        self::PROMOTE_TO_ADMIN,
        self::DEMOTE,
    ];

    /**
     * @param AccessDecisionManagerInterface $accessDecisionManager used to evaluate the actor's roles via the configured role hierarchy
     */
    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    /**
     * Vote only on the six supported attributes with a `User` subject.
     * Any other combination defers.
     *
     * @param string $attribute the attribute being checked
     * @param mixed  $subject   the object the attribute is checked against
     *
     * @return bool true when this voter has an opinion to give
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof User && \in_array($attribute, self::SUPPORTED, true);
    }

    /**
     * Apply the decision rules to the actor / subject pair.
     *
     * @param string         $attribute the attribute being checked (already filtered to one of `SUPPORTED`)
     * @param User           $subject   the user being acted on
     * @param TokenInterface $token     the acting user's authentication token
     * @param Vote|null      $vote      Symfony 8 vote-explanation slot; unused here
     *
     * @return bool true to grant, false to deny
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $actor = $token->getUser();
        if (!$actor instanceof User) {
            return false;
        }

        if (!$this->accessDecisionManager->decide($token, [Roles::DOMAIN_MANAGER])) {
            return false;
        }

        $actorIsAdmin = $this->accessDecisionManager->decide($token, [Roles::ADMIN]);

        // A manager must never act on an admin — same-domain or
        // cross-domain, approve / block / promote / demote, doesn't
        // matter. Blocking an admin would lock them out of the
        // install while keeping their role list intact, which is the
        // same privilege-escalation shape as demoting them, so the
        // guard applies uniformly across every supported attribute.
        if (!$actorIsAdmin && \in_array(Roles::ADMIN, $subject->getRoles(), true)) {
            return false;
        }

        // `PROMOTE_TO_ADMIN` is admin-only — a manager never gets to
        // mint a new admin, even on a same-domain plain user.
        if (self::PROMOTE_TO_ADMIN === $attribute && !$actorIsAdmin) {
            return false;
        }

        if ($actorIsAdmin) {
            return true;
        }

        $actorDomain = EmailDomain::of($actor);
        $subjectDomain = EmailDomain::of($subject);
        if (null === $actorDomain || null === $subjectDomain) {
            return false;
        }

        return $actorDomain === $subjectDomain;
    }
}
