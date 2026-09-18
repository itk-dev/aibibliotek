<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\AssistantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Mine assistenter" — a signed-in user's personal inventory of
 * the assistants they have shared.
 *
 * The subject is always the current security user (`$this->getUser()`),
 * never a path parameter, so there is no way to address another
 * user's inventory through this controller. Access floor is the
 * project's authenticated baseline (`IS_AUTHENTICATED_FULLY`) —
 * the page is visible to every logged-in user regardless of role
 * and 401s anonymous visitors.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class UserAssistantController extends AbstractController
{
    public function __construct(
        private readonly AssistantRepository $assistantRepository,
    ) {
    }

    #[Route(path: '/mine/assistenter', name: 'app_user_assistants', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->render('user/assistants.html.twig', [
            'assistants' => $this->assistantRepository->findCreatedBy($user),
        ]);
    }
}
