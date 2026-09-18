<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Assistant;
use App\Entity\User;
use App\Security\Roles;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorises managing (editing / deleting) a persisted {@see Assistant}.
 *
 * Grants the `EDIT_ASSISTANT` and `DELETE_ASSISTANT` attributes
 * when the actor is either the assistant's original curator
 * (matched via {@see Assistant::$createdBy}) or a site
 * administrator ({@see Roles::ADMIN}). Everyone else is denied
 * — both actions are limited to the same audience so the change
 * history stays unambiguous and matches the wording of the
 * "Rediger" and "Slet" affordances on the detail / "Mine
 * assistenter" surfaces.
 */
final class EditAssistantVoter extends Voter
{
    public const string EDIT = 'EDIT_ASSISTANT';
    public const string DELETE = 'DELETE_ASSISTANT';

    /**
     * @var list<string>
     */
    private const array SUPPORTED = [self::EDIT, self::DELETE];

    /**
     * @param AccessDecisionManagerInterface $accessDecisionManager used to evaluate the actor's roles via the configured role hierarchy
     */
    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    /**
     * Vote on the `EDIT_ASSISTANT` and `DELETE_ASSISTANT` attributes
     * with an {@see Assistant} subject. Any other combination defers.
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
     * Grant when the actor is the assistant's `createdBy` blame
     * user or holds `ROLE_ADMIN`.
     *
     * @param string         $attribute the attribute being checked (`EDIT_ASSISTANT`)
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

        if ($this->accessDecisionManager->decide($token, [Roles::ADMIN])) {
            return true;
        }

        $author = $subject->getCreatedBy();

        return $author instanceof User && $author->getId()?->equals($actor->getId());
    }
}
