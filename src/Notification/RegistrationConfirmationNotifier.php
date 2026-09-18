<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\User;
use App\Mail\EmailTemplateRenderer;
use App\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Sends a confirmation email to a newly-registered user.
 *
 * Subject + body + sender all resolve through {@see SettingsManager}
 * at send time, so admin edits at `/admin/settings/email` take
 * effect immediately without a redeploy. Available `%token%`
 * placeholders the admin can use in both subject and body:
 *
 * - `%name%`        — the user's display name
 * - `%email%`       — the user's e-mail address
 * - `%brand_name%`  — current brand identity
 *
 * When the sender is unset, logs a warning and returns without
 * sending so the registration flow itself stays alive.
 */
class RegistrationConfirmationNotifier
{
    /**
     * @param MailerInterface       $mailer   Symfony Mailer used to dispatch the message
     * @param SettingsManager       $settings typed accessor for the admin-editable templates + sender
     * @param EmailTemplateRenderer $renderer resolves the Markdown template into subject + html + text
     * @param LoggerInterface       $logger   receives a warning when the sender is unset
     */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly SettingsManager $settings,
        private readonly EmailTemplateRenderer $renderer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Dispatch the "thanks, awaiting approval" message to a registered user.
     *
     * Looks up the sender via {@see SettingsManager::getSenderAddress()};
     * if unset, the send is skipped with a warning so the
     * registration succeeds even when the mailer surface is
     * incompletely configured.
     *
     * @param User $user the user the registration was created for
     */
    public function confirmRegistration(User $user): void
    {
        $sender = $this->settings->getSenderAddress();
        if (null === $sender) {
            $this->logger->warning('Sender address is unset; skipping registration confirmation.', [
                'user_email' => $user->getUserIdentifier(),
            ]);

            return;
        }

        $rendered = $this->renderer->render(
            $this->settings->getRegistrationConfirmationSubject(),
            $this->settings->getRegistrationConfirmationBody(),
            [
                'name' => $user->getName(),
                'email' => (string) $user->getEmail(),
                'brand_name' => $this->settings->getBrandName(),
            ],
        );

        $email = (new TemplatedEmail())
            ->from(Address::create($sender))
            ->to(Address::create((string) $user->getEmail()))
            ->subject($rendered->subject)
            ->htmlTemplate('email/registration/confirmation.html.twig')
            ->textTemplate('email/registration/confirmation.txt.twig')
            ->context(['bodyHtml' => $rendered->bodyHtml, 'bodyText' => $rendered->bodyText]);

        $this->mailer->send($email);
    }
}
