<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\User;
use App\Mail\EmailTemplateRenderer;
use App\Security\EmailConfirmation;
use App\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends the single-use email-confirmation link to a newly-registered
 * user.
 *
 * Distinct from {@see RegistrationConfirmationNotifier}: the existing
 * notifier is the "thanks, awaiting moderator approval" courtesy
 * message; this one carries the actionable link the user must click
 * to confirm they own the e-mail address. The link itself is minted
 * by {@see EmailConfirmation::issueToken()} and embedded into the
 * `app_email_confirmation_check` route.
 *
 * Subject + body + sender all resolve through {@see SettingsManager}
 * at send time, so admin edits at `/admin/settings/email` take
 * effect immediately without a redeploy. Available `%token%`
 * placeholders the admin can use in both subject and body:
 *
 * - `%name%`             — the user's display name
 * - `%email%`            — the user's e-mail address
 * - `%brand_name%`       — current brand identity
 * - `%confirmation_url%` — the absolute URL to the single-use
 *                          confirmation route the user must click
 *
 * When the sender is unset, logs a warning and returns without
 * sending so the registration flow itself stays alive.
 */
class EmailConfirmationNotifier
{
    /**
     * @param MailerInterface       $mailer            Symfony Mailer used to dispatch the message
     * @param SettingsManager       $settings          typed accessor for the admin-editable templates + sender
     * @param EmailTemplateRenderer $renderer          resolves the Markdown template into subject + html + text
     * @param EmailConfirmation     $emailConfirmation issues the per-user confirmation token
     * @param UrlGeneratorInterface $urlGenerator      builds the absolute confirmation URL embedded in the email
     * @param LoggerInterface       $logger            receives a warning when the sender is unset
     */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly SettingsManager $settings,
        private readonly EmailTemplateRenderer $renderer,
        private readonly EmailConfirmation $emailConfirmation,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Dispatch the email-confirmation message to a freshly-registered user.
     *
     * Mints a fresh confirmation token via {@see EmailConfirmation::issueToken()}
     * and builds an absolute URL pointing at the
     * `app_email_confirmation_check` route. When the configured
     * sender is unset, skips the send with a warning so the
     * registration flow stays alive even when the mailer surface
     * is incompletely configured.
     *
     * @param User $user the user to send the confirmation link to
     */
    public function sendConfirmationLink(User $user): void
    {
        $sender = $this->settings->getSenderAddress();
        if (null === $sender) {
            $this->logger->warning('Sender address is unset; skipping email confirmation link.', [
                'user_email' => $user->getUserIdentifier(),
            ]);

            return;
        }

        $token = $this->emailConfirmation->issueToken($user);
        $confirmationUrl = $this->urlGenerator->generate(
            'app_email_confirmation_check',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $rendered = $this->renderer->render(
            $this->settings->getEmailConfirmationSubject(),
            $this->settings->getEmailConfirmationBody(),
            [
                'name' => $user->getName(),
                'email' => (string) $user->getEmail(),
                'brand_name' => $this->settings->getBrandName(),
                'confirmation_url' => $confirmationUrl,
            ],
        );

        $email = (new TemplatedEmail())
            ->from(Address::create($sender))
            ->to(Address::create((string) $user->getEmail()))
            ->subject($rendered->subject)
            ->htmlTemplate('email/registration/email_confirmation.html.twig')
            ->textTemplate('email/registration/email_confirmation.txt.twig')
            ->context(['bodyHtml' => $rendered->bodyHtml, 'bodyText' => $rendered->bodyText]);

        $this->mailer->send($email);
    }
}
