<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Entity\Assistant;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\AssistantRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Security\Roles;
use App\Security\UserOrganization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Everything the admin ownership screen needs to answer "who owns
 * which of this organisation's assistants, and who may it move to?".
 *
 * Ownership on this project is the `createdBy` blame stamp: it is what
 * {@see \App\Security\Voter\EditAssistantVoter} reads when deciding
 * whether someone may edit or delete an assistant, and it is the only
 * link between an assistant and a person. That link is nulled when the
 * user row goes away (`onDelete: SET NULL` on the blame column), which
 * is why an organisation needs a way to move ownership to a colleague
 * *before* an employee is removed — otherwise the assistant is left
 * with no one able to maintain it.
 *
 * The organisation an operator acts for is derived from their e-mail
 * domain (see {@see UserOrganization}); site administrators are not
 * bound to their own and choose which organisation to manage.
 */
final class AssistantOwnership
{
    /**
     * @param AssistantRepository    $assistants       source of the per-organisation assistant list
     * @param UserRepository         $users            source of the candidate-owner list
     * @param OrganizationRepository $organizations    used to resolve an explicitly requested organisation
     * @param UserOrganization       $userOrganization resolves the acting user's own organisation
     * @param Security               $security         used for the `ROLE_ADMIN` check that lifts the organisation scope
     * @param EntityManagerInterface $entityManager    persists the reassignment
     */
    public function __construct(
        private readonly AssistantRepository $assistants,
        private readonly UserRepository $users,
        private readonly OrganizationRepository $organizations,
        private readonly UserOrganization $userOrganization,
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * List the organisations this operator may manage assistants for.
     *
     * A site administrator gets every organisation, so the screen can
     * offer a picker; a domain manager gets the single organisation
     * their e-mail domain resolves to, or an empty list when no
     * organisation claims that domain.
     *
     * @param User $actor the operator viewing the screen
     *
     * @return list<Organization> manageable organisations, A→Z by name
     */
    public function manageableOrganizations(User $actor): array
    {
        if ($this->security->isGranted(Roles::ADMIN)) {
            /** @var list<Organization> $all */
            $all = $this->organizations->findBy([], ['name' => 'ASC']);

            return $all;
        }

        $own = $this->userOrganization->of($actor);

        return null === $own ? [] : [$own];
    }

    /**
     * Pick the organisation the screen should show.
     *
     * Honours an explicitly requested id when it names an organisation
     * the operator may manage; otherwise falls back to the first
     * manageable one, which for a domain manager is their own. Returns
     * `null` when the operator manages nothing — the caller renders an
     * empty state rather than treating that as an error, since a
     * manager on an unclaimed e-mail domain is a legitimate state.
     *
     * A requested id the operator may *not* manage falls back rather
     * than throwing: the id arrives from a query string, so a stale
     * bookmark or hand-edited URL should land on a usable page, and no
     * data is exposed because the fallback is always in scope.
     *
     * @param User        $actor       the operator viewing the screen
     * @param string|null $requestedId ULID of the organisation asked for, or `null` for the default
     *
     * @return Organization|null the organisation to show, or `null` when the operator manages none
     */
    public function resolveOrganization(User $actor, ?string $requestedId): ?Organization
    {
        $manageable = $this->manageableOrganizations($actor);
        if ([] === $manageable) {
            return null;
        }

        if (null !== $requestedId && '' !== $requestedId) {
            foreach ($manageable as $organization) {
                if ((string) $organization->getId() === $requestedId) {
                    return $organization;
                }
            }
        }

        return $manageable[0];
    }

    /**
     * List the assistants the given organisation owns.
     *
     * @param Organization $organization the organisation being managed
     *
     * @return list<Assistant> the organisation's assistants, A→Z by title
     */
    public function assistantsFor(Organization $organization): array
    {
        return $this->assistants->findByOrganization($organization);
    }

    /**
     * List the users an assistant in this organisation may be handed to.
     *
     * Restricted to approved members of the organisation itself — an
     * assistant must not leave the municipality that shared it, so the
     * same restriction applies to site administrators.
     *
     * @param Organization $organization the organisation being managed
     *
     * @return list<User> approved members, A→Z by name
     */
    public function candidateOwners(Organization $organization): array
    {
        return $this->users->findApprovedForOrganization($organization);
    }

    /**
     * Reassign ownership of the given assistants to a new owner.
     *
     * Re-validates both halves of the transfer against the
     * organisation, because the ids arrive from a submitted form and
     * the rendered options are not a security boundary: every
     * assistant must belong to `$organization`, and the new owner must
     * be one of its approved members. The whole batch is rejected if
     * any member of it fails, so a tampered row cannot slip through
     * alongside valid ones.
     *
     * Sets the `createdBy` blame stamp, which is what the edit /
     * delete voter reads — so the transfer moves maintenance rights
     * with it rather than merely relabelling the row.
     *
     * @param list<Assistant> $assistants   the assistants to reassign
     * @param User            $newOwner     the user to hand them to
     * @param Organization    $organization the organisation the transfer happens within
     *
     * @return int the number of assistants reassigned
     *
     * @throws \DomainException when the selection is empty, an assistant
     *                          belongs to another organisation, or the new
     *                          owner is not an approved member of it
     */
    public function reassign(array $assistants, User $newOwner, Organization $organization): int
    {
        if ([] === $assistants) {
            throw new \DomainException('admin.assistants.flash.error_no_selection');
        }

        if (!$this->isMember($newOwner, $organization)) {
            throw new \DomainException('admin.assistants.flash.error_owner');
        }

        foreach ($assistants as $assistant) {
            if (!$this->belongsTo($assistant, $organization)) {
                throw new \DomainException('admin.assistants.flash.error_assistant');
            }
        }

        foreach ($assistants as $assistant) {
            $assistant->setCreatedBy($newOwner);
        }
        $this->entityManager->flush();

        return \count($assistants);
    }

    /**
     * Tell whether the assistant is owned by the given organisation.
     *
     * @param Assistant    $assistant    the assistant to check
     * @param Organization $organization the organisation to check against
     *
     * @return bool true when the assistant's organisation is the given one
     */
    private function belongsTo(Assistant $assistant, Organization $organization): bool
    {
        $assistantOrganization = $assistant->getOrganization();
        if (null === $assistantOrganization) {
            return false;
        }

        return (bool) $assistantOrganization->getId()?->equals($organization->getId());
    }

    /**
     * Tell whether the user is an approved member of the organisation.
     *
     * Compares against {@see self::candidateOwners()} rather than
     * re-deriving the domain, so the picker's contents and the
     * server-side check can never drift apart.
     *
     * @param User         $user         the prospective owner
     * @param Organization $organization the organisation to check against
     *
     * @return bool true when the user is offered as a candidate owner
     */
    private function isMember(User $user, Organization $organization): bool
    {
        foreach ($this->candidateOwners($organization) as $candidate) {
            if ((bool) $candidate->getId()?->equals($user->getId())) {
                return true;
            }
        }

        return false;
    }
}
