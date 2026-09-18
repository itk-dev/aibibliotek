<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ProfileType;
use App\Security\UserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Self-service profile editing for the currently-authenticated
 * user.
 *
 * The subject is always read off the security context — never
 * a path parameter — so there is no way to address another
 * user's row through this controller. Authorisation is the
 * project's authenticated floor (`IS_AUTHENTICATED_FULLY`);
 * `ManageUserVoter` and the admin user-management surfaces
 * stay orthogonal to this flow.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly UserManager $userManager,
    ) {
    }

    #[Route(path: '/profile/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $form = $this->createForm(ProfileType::class, ['name' => $user->getName()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: string} $data */
            $data = $form->getData();
            $this->userManager->updateUser($user->getUserIdentifier(), name: $data['name']);
            $this->addFlash('success', 'profile.edit.flash.saved');

            return $this->redirectToRoute('app_profile_edit');
        }

        // 422 on invalid submit so browsers re-render the form with
        // errors instead of caching the POST as a successful page.
        return $this->render('profile/edit.html.twig', [
            'form' => $form,
        ], new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
