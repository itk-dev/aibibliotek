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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Sends the single-use password-reset link to a user who asked for
 * one on `/reset-password`.
 *
 * The token itself is minted via
 * {@see ResetPasswordHelperInterface::generateResetToken()} — same
 * helper the controller uses when it needs the raw token for the
 * confirmation URL. Subject + body + sender all resolve through
 * {@see SettingsManager} at send time so admin edits at
 * `/admin/settings/email` take effect immediately without a
 * redeploy. Available `%token%` placeholders the admin can use in
 * both subject and body:
 *
 * - `%name%`        — the user's display name
 * - `%email%`       — the user's e-mail address
 * - `%brand_name%`  — current brand identity
 * - `%reset_url%`   — the absolute URL to the single-use reset route
 * - `%expires_in%`  — human-readable expiration ("60 minutter") from
 *                     the ResetPasswordBundle's own translation catalogue
 *
 * When the sender is unset, logs a warning and returns without
 * sending so the request flow itself stays alive.
 */
class PasswordResetNotifier
{
    /**
     * @param MailerInterface              $mailer       Symfony Mailer used to dispatch the message
     * @param SettingsManager              $settings     typed accessor for the admin-editable templates + sender
     * @param EmailTemplateRenderer        $renderer     resolves the Markdown template into subject + html + text
     * @param ResetPasswordHelperInterface $resetHelper  mints the per-user reset token
     * @param UrlGeneratorInterface        $urlGenerator builds the absolute reset URL embedded in the email
     * @param TranslatorInterface          $translator   resolves the ResetPasswordBundle's expiration copy
     * @param LoggerInterface              $logger       receives a warning when the sender is unset
     */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly SettingsManager $settings,
        private readonly EmailTemplateRenderer $renderer,
        private readonly ResetPasswordHelperInterface $resetHelper,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Mint a reset token for the user and dispatch the reset link
     * email in one step.
     *
     * Wraps {@see ResetPasswordHelperInterface::generateResetToken()}
     * with the throttling-swallow behaviour the controller previously
     * carried inline: if the bundle refuses to mint a new token
     * (rate-limit hit, unexpired outstanding request), the exception
     * is swallowed and `null` is returned. Callers use the returned
     * token to stash it in the session for the check-email
     * confirmation route; a `null` return leaves the ambiguous
     * "message may or may not have been sent" response shape intact
     * so nothing leaks about the existence of the underlying user.
     *
     * @param User $user the user the reset was requested for
     *
     * @return ResetPasswordToken|null the freshly-minted token when the
     *                                 email was dispatched, `null` when
     *                                 throttling or an outstanding
     *                                 request suppressed the send
     */
    public function requestReset(User $user): ?ResetPasswordToken
    {
        try {
            $token = $this->resetHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface $exception) {
            $this->logger->info('Password reset skipped: {reason}', [
                'reason' => $exception->getReason(),
                'user_email' => $user->getUserIdentifier(),
            ]);

            return null;
        }

        $this->sendResetLink($user, $token);

        return $token;
    }

    /**
     * Dispatch the password-reset message for a pre-minted token.
     *
     * Kept public so tests can dispatch messages without paying the
     * throttling / persistence cost of a real token mint. Production
     * callers should go through {@see self::requestReset()} instead.
     *
     * @param User               $user  the user the reset was requested for
     * @param ResetPasswordToken $token the freshly-minted reset token whose value the URL embeds
     */
    public function sendResetLink(User $user, ResetPasswordToken $token): void
    {
        $sender = $this->settings->getSenderAddress();
        if (null === $sender) {
            $this->logger->warning('Sender address is unset; skipping password reset link.', [
                'user_email' => $user->getUserIdentifier(),
            ]);

            return;
        }

        $resetUrl = $this->urlGenerator->generate(
            'app_reset_password',
            ['token' => $token->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $expiresIn = $this->translator->trans(
            $token->getExpirationMessageKey(),
            $token->getExpirationMessageData(),
            'ResetPasswordBundle',
        );

        $rendered = $this->renderer->render(
            $this->settings->getPasswordResetSubject(),
            $this->settings->getPasswordResetBody(),
            [
                'name' => $user->getName(),
                'email' => (string) $user->getEmail(),
                'brand_name' => $this->settings->getBrandName(),
                'reset_url' => $resetUrl,
                'expires_in' => $expiresIn,
            ],
        );

        $email = (new TemplatedEmail())
            ->from(Address::create($sender))
            ->to(Address::create((string) $user->getEmail()))
            ->subject($rendered->subject)
            ->htmlTemplate('email/password_reset/reset.html.twig')
            ->textTemplate('email/password_reset/reset.txt.twig')
            ->context(['bodyHtml' => $rendered->bodyHtml, 'bodyText' => $rendered->bodyText]);

        $this->mailer->send($email);
    }
}
