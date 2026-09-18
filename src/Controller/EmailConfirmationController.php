<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\EmailConfirmation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EmailConfirmationController extends AbstractController
{
    public function __construct(private readonly EmailConfirmation $emailConfirmation)
    {
    }

    #[Route(path: '/auth/confirm-email/{token}', name: 'app_email_confirmation_check', methods: ['GET'])]
    public function check(string $token): Response
    {
        $user = $this->emailConfirmation->consume($token);

        if (null === $user) {
            return $this->render(
                'auth/email_confirmation_invalid.html.twig',
                [],
                new Response('', Response::HTTP_GONE),
            );
        }

        return $this->render('auth/email_confirmation_success.html.twig', [
            'name' => $user->getName(),
        ]);
    }
}
