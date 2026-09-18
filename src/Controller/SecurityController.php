<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Routes for interactive authentication.
 *
 * `/login` renders the form and surfaces the previous error / last
 * username via {@see AuthenticationUtils}. The credential check is
 * performed by the `form_login` authenticator declared in
 * `security.yaml`, not here. `/logout` is intercepted by the firewall.
 */
final class SecurityController extends AbstractController
{
    /**
     * Render the login form.
     *
     * @param AuthenticationUtils $authenticationUtils Security helper for the previous error + last username
     *
     * @return Response the rendered login template
     */
    #[Route(path: '/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Placeholder action for the `app_logout` route.
     *
     * The firewall intercepts the request and clears the session, so
     * the body is unreachable in production. The throw guards against
     * an accidental direct call.
     *
     * @throws \LogicException always — the firewall must intercept
     */
    #[Route(path: '/logout', name: 'app_logout', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function logout(): never
    {
        throw new \LogicException('This method is intercepted by the logout key on the firewall.');
    }
}
