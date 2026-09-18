<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Twig\Environment;

/**
 * Entry point that returns HTTP 401 with a branded page when an
 * anonymous request hits a protected route.
 *
 * Symfony's default form-login entry point would answer the
 * unauthorised request with a 302 to the login form. That's the
 * standard browser-app pattern, but it reads as "this resource has
 * temporarily moved" — a misleading signal when the resource is
 * actually protected, not relocated. Returning 401 makes the
 * access decision explicit at the HTTP layer.
 *
 * Without a body, browsers render their own built-in error UI for
 * a 401 — Firefox in particular shows a stark "server sent back an
 * error" page. Rendering `security/unauthorized.html.twig` keeps
 * the brand chrome and provides a clear "log in" CTA.
 *
 * The 403 counterpart for already-authenticated users lives in
 * {@see AccessDeniedHandler}.
 */
final class UnauthorizedEntryPoint implements AuthenticationEntryPointInterface
{
    /**
     * @param Environment $twig Twig environment used to render the 401 template
     */
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * Build the 401 response the firewall returns on anonymous access.
     *
     * @param Request                      $request       the incoming request that triggered the entry point
     * @param AuthenticationException|null $authException the underlying authentication failure, when present
     *
     * @return Response templated 401 response
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new Response(
            $this->twig->render('security/unauthorized.html.twig'),
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
