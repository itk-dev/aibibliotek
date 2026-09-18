<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Twig\Environment;

/**
 * Render a branded HTTP 403 page when an authenticated user hits
 * a route they don't have the role for.
 *
 * Symfony's default behaviour is to emit a blank 403 response —
 * the request stops at the firewall and never reaches the
 * profiler / Twig stack. This handler intercepts the
 * `AccessDeniedException`, renders the same `security/access_denied`
 * template the project's other public pages extend, and returns
 * it with status 403 so HTTP semantics stay correct.
 *
 * Anonymous requests on protected routes still go through
 * {@see UnauthorizedEntryPoint} and return 401 — those are an
 * authentication problem, not an authorisation one, and the two
 * paths intentionally render differently.
 *
 * Wired in `config/packages/security.yaml` under
 * `firewalls.main.access_denied_handler`.
 *
 * @see https://symfony.com/doc/current/security/access_denied_handler.html
 */
final class AccessDeniedHandler implements AccessDeniedHandlerInterface
{
    /**
     * @param Environment $twig Twig environment used to render the 403 template
     */
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * Render the templated 403 response for the failed authorisation.
     *
     * @param Request               $request               the request that tripped the access decision
     * @param AccessDeniedException $accessDeniedException the underlying exception (not currently surfaced to the template, but available for future per-reason rendering)
     *
     * @return Response a `text/html` response with HTTP 403 and the rendered template
     */
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return new Response(
            $this->twig->render('security/access_denied.html.twig'),
            Response::HTTP_FORBIDDEN,
        );
    }
}
