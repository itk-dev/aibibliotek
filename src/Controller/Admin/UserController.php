<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Form\UserCreateType;
use App\Repository\UserRepository;
use App\Security\LastAdminException;
use App\Security\Roles;
use App\Security\UserApproval;
use App\Security\UserManager;
use App\Security\UserRoles;
use App\Security\Voter\ManageUserVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(Roles::DOMAIN_MANAGER)]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserApproval $userApproval,
        private readonly UserManager $userManager,
        private readonly UserRoles $userRoles,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Map from the JSON-payload `role` string to the matching voter
     * attribute and the {@see UserRoles} action that applies it.
     *
     * @var array<string, array{attribute: string, action: string}>
     */
    private const array ROLE_TRANSITIONS = [
        'admin' => ['attribute' => ManageUserVoter::PROMOTE_TO_ADMIN, 'action' => 'promoteToAdmin'],
        'manager' => ['attribute' => ManageUserVoter::PROMOTE_TO_MANAGER, 'action' => 'promoteToManager'],
        'none' => ['attribute' => ManageUserVoter::DEMOTE, 'action' => 'removeAllPermissions'],
    ];

    #[Route(path: '/admin/users', name: 'app_admin_users', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $actor = $this->currentUser();
        $statusFilter = $this->resolveStatusFilter((string) $request->query->get('status', ''));

        return $this->render('admin/user/list.html.twig', [
            'users' => $this->userRepository->findVisibleTo($actor, $statusFilter),
            'status_filter' => $statusFilter,
        ]);
    }

    #[Route(path: '/admin/users/pending', name: 'app_admin_users_pending', methods: ['GET'])]
    public function pending(): Response
    {
        return $this->redirectToRoute('app_admin_users', ['status' => UserStatus::Pending->value]);
    }

    #[Route(path: '/admin/users/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    #[IsGranted(Roles::ADMIN)]
    public function new(Request $request): Response
    {
        $form = $this->createForm(UserCreateType::class);
        $form->handleRequest($request);

        $domainError = null;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->userManager->createFromInput($form->getData());

                $this->addFlash('success', 'admin.users.flash.created');

                return $this->redirectToRoute('app_admin_users');
            } catch (\DomainException $e) {
                $domainError = $e->getMessage();
            }
        }

        // 422 on invalid submit so Turbo / browsers re-render the form with
        // errors instead of caching the POST as a successful page.
        $invalid = $form->isSubmitted() && (!$form->isValid() || null !== $domainError);

        return $this->render('admin/user/new.html.twig', [
            'form' => $form,
            'domain_error' => $domainError,
        ], new Response('', $invalid ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route(path: '/admin/users/{id}/approve', name: 'app_admin_user_approve', methods: ['POST'], requirements: ['id' => Requirement::ULID])]
    #[IsGranted(ManageUserVoter::APPROVE, subject: 'user')]
    public function approve(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin-user-action', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->userApproval->approve($user);
        $this->addFlash('success', 'admin.users.flash.approved');

        return $this->redirectToBackUrl($request);
    }

    #[Route(path: '/admin/users/{id}/block', name: 'app_admin_user_block', methods: ['POST'], requirements: ['id' => Requirement::ULID])]
    #[IsGranted(ManageUserVoter::BLOCK, subject: 'user')]
    public function block(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin-user-action', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->userApproval->block($user);
        } catch (LastAdminException) {
            // Blocking the last active admin would lock the site. The
            // guard lives in the service; surface it as an error flash
            // and bounce back to the list without mutating anything.
            $this->addFlash('error', 'admin.users.flash.last_admin_block');

            return $this->redirectToBackUrl($request);
        }

        $this->addFlash('success', 'admin.users.flash.blocked');

        return $this->redirectToBackUrl($request);
    }

    #[Route(path: '/admin/users/{id}/role', name: 'app_admin_user_role', methods: ['POST'], requirements: ['id' => Requirement::ULID])]
    public function role(User $user, Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), associative: true);
        if (!\is_array($payload)) {
            $payload = [];
        }

        if (!$this->isCsrfTokenValid('admin-user-action', (string) ($payload['_token'] ?? ''))) {
            return $this->jsonError('csrf', 'admin.users.role.flash.error_csrf', Response::HTTP_FORBIDDEN);
        }

        $roleKey = (string) ($payload['role'] ?? '');
        $transition = self::ROLE_TRANSITIONS[$roleKey] ?? null;
        if (null === $transition) {
            return $this->jsonError('invalid_role', 'admin.users.role.flash.error_invalid', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$this->isGranted($transition['attribute'], $user)) {
            return $this->jsonError('forbidden', 'admin.users.role.flash.error_forbidden', Response::HTTP_FORBIDDEN);
        }

        try {
            $action = $transition['action'];
            $this->userRoles->$action($user);
        } catch (LastAdminException) {
            return $this->jsonError('last_admin', 'admin.users.role.flash.error_last_admin', Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'role' => $roleKey,
            'label' => $this->translator->trans('admin.users.role.'.$roleKey),
        ]);
    }

    /**
     * Build a JSON error envelope shared by every failure branch of
     * the role endpoint, so the Stimulus client has a uniform shape
     * to surface as inline feedback.
     *
     * @param string $code    machine-readable failure reason
     * @param string $message translation key the client renders into the inline alert
     * @param int    $status  HTTP status code to return
     *
     * @return JsonResponse the error envelope with `{ error, message }`
     */
    private function jsonError(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => $code,
            'message' => $this->translator->trans($message),
        ], $status);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function resolveStatusFilter(string $raw): ?UserStatus
    {
        if ('' === $raw) {
            return null;
        }

        return UserStatus::tryFrom($raw);
    }

    private function redirectToBackUrl(Request $request): Response
    {
        $back = (string) $request->request->get('back', '');
        if (str_starts_with($back, '/admin/users')) {
            return $this->redirect($back);
        }

        return $this->redirectToRoute('app_admin_users');
    }
}
