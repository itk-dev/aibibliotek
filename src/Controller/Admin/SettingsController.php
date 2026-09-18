<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Mail\EmailTemplateRenderer;
use App\Security\Roles;
use App\Settings\SettingsManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(Roles::ADMIN)]
final class SettingsController extends AbstractController
{
    /**
     * CSRF intent for the preview JSON endpoint. Kept distinct
     * from the enclosing form's `admin-settings-email` intent so
     * a stale carrier form can be refreshed without invalidating
     * an in-flight main-form submit.
     */
    private const string PREVIEW_CSRF_INTENT = 'admin-settings-email-preview';

    /**
     * Placeholder value substituted when the acting admin's `name`
     * field is empty. Keeps the preview readable rather than
     * showing a blank line where the greeting would be.
     */
    private const string PREVIEW_NAME_FALLBACK = 'Forhåndsvisning';

    /**
     * Synthetic token embedded in the preview `%reset_url%` so admins
     * can see the shape of the link without minting a real token.
     * Matches the "60 minutter" copy the ResetPasswordBundle emits
     * for `%expires_in%` so the two placeholders read consistently.
     */
    private const string PREVIEW_RESET_TOKEN = 'preview-token';

    /**
     * Synthetic value substituted for `%expires_in%` in the preview.
     * Mirrors the human-readable expiration the ResetPasswordBundle
     * emits at send time — hard-coded here so the preview does not
     * need to reach into the bundle's translation catalogue.
     */
    private const string PREVIEW_EXPIRES_IN = '60 minutter';

    public function __construct(
        private readonly SettingsManager $settingsManager,
        private readonly EmailTemplateRenderer $emailTemplateRenderer,
    ) {
    }

    #[Route(path: '/admin/settings', name: 'app_admin_settings', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_admin_settings_site');
    }

    #[Route(path: '/admin/settings/site', name: 'app_admin_settings_site', methods: ['GET', 'POST'])]
    public function site(Request $request): Response
    {
        $submitted = [
            'brand_name' => $this->settingsManager->getBrandName(),
            'brand_tagline' => $this->settingsManager->getBrandTagline(),
            'brand_initials' => $this->settingsManager->getBrandInitials(),
            'hero_text' => $this->settingsManager->getHeroText(),
        ];

        if ('POST' === $request->getMethod()) {
            $submitted = [
                'brand_name' => (string) $request->request->get('brand_name', ''),
                'brand_tagline' => (string) $request->request->get('brand_tagline', ''),
                'brand_initials' => (string) $request->request->get('brand_initials', ''),
                'hero_text' => (string) $request->request->get('hero_text', ''),
            ];

            if (!$this->isCsrfTokenValid('admin-settings-site', (string) $request->request->get('_token'))) {
                return $this->render('admin/settings/site.html.twig', [
                    'submitted' => $submitted,
                    'error' => 'admin.settings.error.invalid_token',
                ], new Response('', Response::HTTP_FORBIDDEN));
            }

            $this->settingsManager->applyBrandIdentity(
                $submitted['brand_name'],
                $submitted['brand_tagline'],
                $submitted['brand_initials'],
                $submitted['hero_text'],
            );
            $this->addFlash('success', 'admin.settings.flash.saved');

            return $this->redirectToRoute('app_admin_settings_site');
        }

        return $this->render('admin/settings/site.html.twig', [
            'submitted' => $submitted,
            'error' => null,
        ]);
    }

    #[Route(path: '/admin/settings/email', name: 'app_admin_settings_email', methods: ['GET', 'POST'])]
    public function email(Request $request): Response
    {
        $submitted = [
            'admin_recipient' => $this->settingsManager->getAdminRecipient() ?? '',
            'admin_notification_subject' => $this->settingsManager->getAdminNotificationSubject(),
            'admin_notification_body' => $this->settingsManager->getAdminNotificationBody(),
            'registration_confirmation_subject' => $this->settingsManager->getRegistrationConfirmationSubject(),
            'registration_confirmation_body' => $this->settingsManager->getRegistrationConfirmationBody(),
            'email_confirmation_subject' => $this->settingsManager->getEmailConfirmationSubject(),
            'email_confirmation_body' => $this->settingsManager->getEmailConfirmationBody(),
            'password_reset_subject' => $this->settingsManager->getPasswordResetSubject(),
            'password_reset_body' => $this->settingsManager->getPasswordResetBody(),
        ];

        if ('POST' === $request->getMethod()) {
            $submitted = [
                'admin_recipient' => (string) $request->request->get('admin_recipient', ''),
                'admin_notification_subject' => (string) $request->request->get('admin_notification_subject', ''),
                'admin_notification_body' => (string) $request->request->get('admin_notification_body', ''),
                'registration_confirmation_subject' => (string) $request->request->get('registration_confirmation_subject', ''),
                'registration_confirmation_body' => (string) $request->request->get('registration_confirmation_body', ''),
                'email_confirmation_subject' => (string) $request->request->get('email_confirmation_subject', ''),
                'email_confirmation_body' => (string) $request->request->get('email_confirmation_body', ''),
                'password_reset_subject' => (string) $request->request->get('password_reset_subject', ''),
                'password_reset_body' => (string) $request->request->get('password_reset_body', ''),
            ];

            if (!$this->isCsrfTokenValid('admin-settings-email', (string) $request->request->get('_token'))) {
                return $this->render('admin/settings/email.html.twig', [
                    'submitted' => $submitted,
                    'error' => 'admin.settings.error.invalid_token',
                ], new Response('', Response::HTTP_FORBIDDEN));
            }

            $adminRecipient = $this->settingsManager->validateAdminRecipient($submitted['admin_recipient']);
            if (false === $adminRecipient) {
                return $this->render('admin/settings/email.html.twig', [
                    'submitted' => $submitted,
                    'error' => 'admin.settings.error.invalid_email',
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            // Persist the recipient + email templates together so a
            // later invalid field cannot leave an earlier one partially
            // saved. Sender address (`From:`) is deploy-time only via
            // MAILER_FROM — not editable through the admin UI.
            $this->settingsManager->setAdminRecipient($adminRecipient);

            $this->settingsManager->applyEmailContent(
                $submitted['admin_notification_subject'],
                $submitted['admin_notification_body'],
                $submitted['registration_confirmation_subject'],
                $submitted['registration_confirmation_body'],
                $submitted['email_confirmation_subject'],
                $submitted['email_confirmation_body'],
                $submitted['password_reset_subject'],
                $submitted['password_reset_body'],
            );

            $this->addFlash('success', 'admin.settings.flash.saved');

            return $this->redirectToRoute('app_admin_settings_email');
        }

        return $this->render('admin/settings/email.html.twig', [
            'submitted' => $submitted,
            'error' => null,
        ]);
    }

    /**
     * Render a preview of the currently-typed subject + Markdown
     * body for the admin editor.
     *
     * Reuses {@see EmailTemplateRenderer} so the output matches
     * what the mailer would ship — token substitution first, then
     * CommonMark. Tokens are filled from the acting admin's own
     * profile (`name`, `email`), the current brand name, and
     * synthetic URLs (`approval_url`, `confirmation_url`,
     * `reset_url`) plus a synthetic `expires_in` copy so previews of
     * every admin-editable message read realistically without
     * depending on fixture data or minting real tokens.
     *
     * The endpoint is admin-gated via the class-level `IsGranted`
     * attribute and CSRF-protected against a dedicated intent —
     * the enclosing form's intent is deliberately not reused so a
     * stale preview cookie cannot invalidate a legitimate settings
     * submit.
     *
     * Response shape: `{"subject": string, "html": string}`.
     */
    #[Route(
        path: '/admin/settings/email/preview',
        name: 'app_admin_settings_email_preview',
        methods: ['POST'],
    )]
    public function emailPreview(Request $request, UrlGeneratorInterface $urlGenerator): JsonResponse
    {
        /** @var array{subject?: string, body?: string, _token?: string} $payload */
        $payload = json_decode((string) $request->getContent(), associative: true) ?: [];

        if (!$this->isCsrfTokenValid(self::PREVIEW_CSRF_INTENT, (string) ($payload['_token'] ?? ''))) {
            return new JsonResponse(
                ['error' => 'csrf'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $actor = $this->getUser();
        \assert($actor instanceof User);

        $tokens = [
            'name' => '' !== $actor->getName() ? $actor->getName() : self::PREVIEW_NAME_FALLBACK,
            'email' => $actor->getEmail(),
            'brand_name' => $this->settingsManager->getBrandName(),
            'approval_url' => $urlGenerator->generate(
                'app_admin_users',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'confirmation_url' => $urlGenerator->generate(
                'app_frontpage',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'reset_url' => $urlGenerator->generate(
                'app_reset_password',
                ['token' => self::PREVIEW_RESET_TOKEN],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'expires_in' => self::PREVIEW_EXPIRES_IN,
        ];

        $rendered = $this->emailTemplateRenderer->render(
            (string) ($payload['subject'] ?? ''),
            (string) ($payload['body'] ?? ''),
            $tokens,
        );

        return new JsonResponse([
            'subject' => $rendered->subject,
            'html' => $rendered->bodyHtml,
        ]);
    }
}
