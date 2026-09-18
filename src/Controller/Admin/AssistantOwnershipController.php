<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Assistant\AssistantOwnership;
use App\Entity\Assistant;
use App\Entity\User;
use App\Repository\AssistantRepository;
use App\Repository\UserRepository;
use App\Security\Roles;
use App\Security\Voter\OrganizationAssistantVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

#[IsGranted(Roles::DOMAIN_MANAGER)]
#[Route(path: '/admin/assistants', name: 'app_admin_assistants')]
final class AssistantOwnershipController extends AbstractController
{
    public function __construct(
        private readonly AssistantOwnership $ownership,
        private readonly AssistantRepository $assistants,
        private readonly UserRepository $users,
    ) {
    }

    #[Route(path: '', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $this->currentUser();
        $organization = $this->ownership->resolveOrganization($actor, $request->query->get('organization'));

        return $this->render('admin/assistant/list.html.twig', [
            'organizations' => $this->ownership->manageableOrganizations($actor),
            'organization' => $organization,
            'assistants' => null === $organization ? [] : $this->ownership->assistantsFor($organization),
            'candidate_owners' => null === $organization ? [] : $this->ownership->candidateOwners($organization),
        ]);
    }

    #[Route(path: '/reassign', name: '_reassign', methods: ['POST'])]
    public function reassign(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin-assistant-reassign', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $actor = $this->currentUser();
        $organization = $this->ownership->resolveOrganization($actor, $request->request->getString('organization'));
        if (null === $organization) {
            throw $this->createNotFoundException();
        }

        $selected = $this->selectedAssistants($request);
        foreach ($selected as $assistant) {
            $this->denyAccessUnlessGranted(OrganizationAssistantVoter::TRANSFER, $assistant);
        }

        try {
            $newOwner = $this->findUser($request->request->getString('owner'));
            if (!$newOwner instanceof User) {
                throw new \DomainException('admin.assistants.flash.error_owner');
            }

            $count = $this->ownership->reassign($selected, $newOwner, $organization);
            $this->addFlash('success', ['key' => 'admin.assistants.flash.reassigned', 'count' => $count]);
        } catch (\DomainException $e) {
            $this->addFlash('error', ['key' => $e->getMessage(), 'count' => 0]);
        }

        return $this->redirectToRoute('app_admin_assistants', ['organization' => (string) $organization->getId()]);
    }

    /**
     * Load the assistants named by the submitted `assistants[]` ids.
     *
     * Ids that are not well-formed ULIDs, or that name no row, are
     * dropped rather than failing the request: they can only come from
     * a tampered payload or a row deleted between render and submit,
     * and the authorisation check plus the service's organisation
     * re-validation still guard every id that does resolve.
     *
     * @param Request $request the submitted reassignment form
     *
     * @return list<Assistant> the assistants that resolved
     */
    private function selectedAssistants(Request $request): array
    {
        $selected = [];
        foreach ($request->request->all('assistants') as $id) {
            if (!\is_string($id) || !Ulid::isValid($id)) {
                continue;
            }

            $assistant = $this->assistants->find($id);
            if ($assistant instanceof Assistant) {
                $selected[] = $assistant;
            }
        }

        return $selected;
    }

    /**
     * Load a user by submitted id, guarding the ULID shape.
     *
     * Doctrine's ULID type raises a conversion error on a malformed
     * id, so validate before the lookup and treat anything unusable as
     * "no such user" — the caller turns that into the same inline
     * error a real miss produces.
     *
     * @param string $id the submitted user id
     *
     * @return User|null the user, or `null` when the id is malformed or unknown
     */
    private function findUser(string $id): ?User
    {
        return Ulid::isValid($id) ? $this->users->find($id) : null;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
