<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\RateLimitedRegistrationException;
use App\Security\Registration;
use App\Security\RegistrationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    public function __construct(private readonly Registration $registration)
    {
    }

    #[Route(path: '/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_frontpage');
        }

        $submitted = [
            'email' => '',
            'name' => '',
        ];
        $error = null;
        $status = Response::HTTP_OK;

        if ('POST' === $request->getMethod()) {
            $submitted['email'] = (string) $request->request->get('email', '');
            $submitted['name'] = (string) $request->request->get('name', '');
            $plainPassword = (string) $request->request->get('password', '');
            $plainPasswordConfirm = (string) $request->request->get('password_confirm', '');

            if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_token'))) {
                return $this->render('registration/register.html.twig', [
                    'submitted' => $submitted,
                    'error' => 'register.error.invalid_token',
                ], new Response('', Response::HTTP_FORBIDDEN));
            }

            try {
                $this->registration->register(
                    (string) $request->getClientIp(),
                    $submitted['email'],
                    $submitted['name'],
                    $plainPassword,
                    $plainPasswordConfirm,
                );

                return $this->redirectToRoute('app_register_pending');
            } catch (RateLimitedRegistrationException $e) {
                $error = $e->getMessage();
                $status = Response::HTTP_TOO_MANY_REQUESTS;
            } catch (RegistrationException $e) {
                $error = $e->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('registration/register.html.twig', [
            'submitted' => $submitted,
            'error' => $error,
        ], new Response('', $status));
    }

    #[Route(path: '/register/pending', name: 'app_register_pending', methods: ['GET'])]
    public function pending(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_frontpage');
        }

        return $this->render('registration/pending.html.twig');
    }
}
