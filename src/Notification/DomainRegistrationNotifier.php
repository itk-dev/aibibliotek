<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\User;
use App\Mail\EmailTemplateRenderer;
use App\Repository\UserRepository;
use App\Security\EmailDomain;
use App\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends the "new pending user on your domain" email to every
 * approved user with `ROLE_DOMAIN_MANAGER` or `ROLE_ADMIN` whose
 * email domain matches the newly confirmed user's domain.
 *
 * Runs alongside {@see AdminRegistrationNotifier} — the site-wide
 * admin recipient (configured under `/admin/settings/email`) still
 * receives their mail; this notifier adds a scoped signal to every
 * user on the target domain who can act on the queue (a same-
 * domain admin plus every same-domain domain manager). A site
 * admin whose email happens to also be the configured admin
 * recipient will receive two messages in that specific
 * configuration; deduplication is intentionally not attempted here
 * because the two notifications are independent (the admin
 * recipient can point at a shared ops mailbox instead of a
 * `User` row).
 *
 * The mail body reuses the admin-editable subject + body pair
 * (`admin_notification_subject` / `admin_notification_body`) via
 * {@see SettingsManager}, so edits at `/admin/settings/email` take
 * effect for both audiences at once. Available `%token%`
 * placeholders match the admin notifier:
 *
 * - `%name%`         — the newly-confirmed user's display name
 * - `%email%`        — the newly-confirmed user's e-mail address
 * - `%brand_name%`   — current brand identity
 * - `%approval_url%` — absolute URL to the pending-users admin queue
 *
 * When the sender address is unset or the recipient set is empty,
 * the send is skipped (warning / info log respectively). A
 * transport failure on one recipient is logged and swallowed so
 * the loop reaches every remaining recipient and the calling
 * status transition is never rolled back.
 */
class DomainRegistrationNotifier
{
    /**
     * @param MailerInterface       $mailer         Symfony Mailer used to dispatch the message per recipient
     * @param SettingsManager       $settings       typed accessor for the admin-editable templates and sender
     * @param EmailTemplateRenderer $renderer       resolves the Markdown template into subject + html + text
     * @param UrlGeneratorInterface $urlGenerator   absolute-URL helper for the `%approval_url%` token
     * @param UserRepository        $userRepository resolves the approver recipient set for a given domain
     * @param LoggerInterface       $logger         receives a warning on sender-unset / transport failure, info on empty recipient set
     */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly SettingsManager $settings,
        private readonly EmailTemplateRenderer $renderer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Dispatch the domain-scoped notification for a freshly-confirmed user.
     *
     * Extracts the user's email domain, resolves every approver on
     * that domain via {@see UserRepository::findApproversForDomain()},
     * and sends one mail per approver. Skips silently (with an info
     * log) when the recipient set is empty — the admin recipient
     * still receives their own mail via {@see AdminRegistrationNotifier},
     * so no signal is lost. Skips with a warning if the sender
     * address is unset or the user has no resolvable domain.
     *
     * @param User $user the user whose status just flipped to `Pending`
     */
    public function notifyOfNewRegistration(User $user): void
    {
        $domain = EmailDomain::of($user);
        if (null === $domain) {
            $this->logger->warning('Cannot resolve domain from user email; skipping domain notification.', [
                'user_email' => $user->getUserIdentifier(),
            ]);

            return;
        }

        $sender = $this->settings->getSenderAddress();
        if (null === $sender) {
            $this->logger->warning('Sender address is unset; skipping domain notification.', [
                'user_email' => $user->getUserIdentifier(),
            ]);

            return;
        }

        $recipients = $this->userRepository->findApproversForDomain($domain);
        if ([] === $recipients) {
            $this->logger->info('No approver on the user domain; skipping domain notification.', [
                'user_email' => $user->getUserIdentifier(),
                'domain' => $domain,
            ]);

            return;
        }

        $rendered = $this->renderer->render(
            $this->settings->getAdminNotificationSubject(),
            $this->settings->getAdminNotificationBody(),
            [
                'name' => $user->getName(),
                'email' => (string) $user->getEmail(),
                'brand_name' => $this->settings->getBrandName(),
                'approval_url' => $this->urlGenerator->generate(
                    'app_admin_users',
                    ['status' => 'pending'],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ],
        );

        foreach ($recipients as $approver) {
            $recipient = (string) $approver->getEmail();
            $email = (new TemplatedEmail())
                ->from(Address::create($sender))
                ->to(Address::create($recipient))
                ->subject($rendered->subject)
                ->htmlTemplate('email/admin/new_registration.html.twig')
                ->textTemplate('email/admin/new_registration.txt.twig')
                ->context(['bodyHtml' => $rendered->bodyHtml, 'bodyText' => $rendered->bodyText]);

            try {
                $this->mailer->send($email);
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('Failed to deliver domain registration notification.', [
                    'user_email' => $user->getUserIdentifier(),
                    'recipient_email' => $recipient,
                    'exception' => $e,
                ]);
            }
        }
    }
}
