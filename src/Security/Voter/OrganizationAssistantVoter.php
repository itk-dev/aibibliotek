<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Assistant;
use App\Entity\User;
use App\Security\Roles;
use App\Security\UserOrganization;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorises assistant actions on organisational grounds, alongside
 * the authorship rule in {@see EditAssistantVoter}.
 *
 * Two voters answer `EDIT_ASSISTANT` / `DELETE_ASSISTANT` because the
 * two grants have nothing to do with each other: one is "you wrote
 * it", the other is "it belongs to the municipality you manage".
 * Symfony's affirmative decision strategy grants as soon as either
 * says yes, so composing them keeps each rule readable on its own
 * instead of interleaving both inside one `voteOnAttribute()`.
 *
 * Rules, uniform across every supported attribute:
 *
 * 1. The actor must hold {@see Roles::DOMAIN_MANAGER}. A plain user
 *    never reaches an organisational grant — their route to editing
 *    is authorship, which the other voter answers.
 * 2. A site administrator ({@see Roles::ADMIN}) is granted across
 *    every organisation, matching how {@see ManageUserVoter} treats
 *    admins as unscoped.
 * 3. Otherwise the assistant's organisation must be the one the actor
 *    is derived to belong to (see {@see UserOrganization}). An
 *    assistant with no organisation, or an actor whose e-mail domain
 *    no organisation claims, is denied.
 *
 * `TRANSFER_ASSISTANT` is deliberately *not* granted to the
 * assistant's own author: reassigning ownership is a
 * municipality-level correction, so it stays with the domain manager
 * and the site admin even for an assistant the actor wrote.
 */
final class OrganizationAssistantVoter extends Voter
{
    /**
     * Reassign an assistant's `createdBy` owner to another user in the
     * same organisation.
     */
    public const string TRANSFER = 'TRANSFER_ASSISTANT';

    /**
     * @var list<string>
     */
    private const array SUPPORTED = [
        EditAssistantVoter::EDIT,
        EditAssistantVoter::DELETE,
        self::TRANSFER,
    ];

    /**
     * @param AccessDecisionManagerInterface $accessDecisionManager used to evaluate the actor's roles via the configured role hierarchy
     * @param UserOrganization               $userOrganization      resolves the acting user's organisation from their e-mail domain
     */
    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly UserOrganization $userOrganization,
    ) {
    }

    /**
     * Vote on the three supported attributes with an {@see Assistant}
     * subject. Any other combination defers.
     *
     * @param string $attribute the attribute being checked
     * @param mixed  $subject   the object the attribute is checked against
     *
     * @return bool true when this voter has an opinion to give
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Assistant && \in_array($attribute, self::SUPPORTED, true);
    }

    /**
     * Grant when the actor manages the assistant's organisation, or is
     * a site administrator.
     *
     * @param string         $attribute the attribute being checked (already filtered to one of `SUPPORTED`)
     * @param Assistant      $subject   the assistant being acted on
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

        if ($this->accessDecisionManager->decide($token, [Roles::ADMIN])) {
            return true;
        }

        return $this->userOrganization->covers($actor, $subject->getOrganization());
    }
}
