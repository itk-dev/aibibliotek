<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Entity\User;
use App\Mail\EmailTemplateRenderer;
use App\Notification\PasswordResetNotifier;
use App\Settings\SettingsManager;
use League\CommonMark\CommonMarkConverter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Unit coverage of {@see PasswordResetNotifier}.
 *
 * Two branches: skip-with-warning when the sender is unset, and
 * happy-path send when everything is wired.
 */
final class PasswordResetNotifierTest extends TestCase
{
    // Ensures the notifier skips the send with a warning when the sender is unset — a fresh install with no MAILER_FROM must not crash the reset flow.
    public function testSkipsSendWhenSenderAddressIsUnset(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $settings = $this->createStub(SettingsManager::class);
        $settings->method('getSenderAddress')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $notifier = new PasswordResetNotifier(
            $mailer,
            $settings,
            new EmailTemplateRenderer(new CommonMarkConverter()),
            $this->createStub(ResetPasswordHelperInterface::class),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(TranslatorInterface::class),
            $logger,
        );

        $user = (new User())->setEmail('curator@example.test');
        $token = new ResetPasswordToken('raw-token', new \DateTimeImmutable('+1 hour'), (new \DateTimeImmutable('+1 hour'))->getTimestamp());

        $notifier->sendResetLink($user, $token);
    }

    // Verifies the happy path sends one email to the user's address, populated by the rendered subject + html + text.
    public function testSendsRenderedEmailToUser(): void
    {
        $captured = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->willReturnCallback(static function (Email $email) use (&$captured): void {
                $captured = $email;
            });

        $settings = $this->createStub(SettingsManager::class);
        $settings->method('getSenderAddress')->willReturn('sender@example.test');
        $settings->method('getPasswordResetSubject')->willReturn('Nulstil %name%');
        $settings->method('getPasswordResetBody')->willReturn('Klik: %reset_url%');
        $settings->method('getBrandName')->willReturn('AI-reolen');

        $renderer = new EmailTemplateRenderer(new CommonMarkConverter());

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://x/reset/abc');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('60 minutter');

        $notifier = new PasswordResetNotifier(
            $mailer,
            $settings,
            $renderer,
            $this->createStub(ResetPasswordHelperInterface::class),
            $urls,
            $translator,
            $this->createMock(LoggerInterface::class),
        );

        $user = (new User())->setEmail('curator@example.test');
        $user->setName('Curator');
        $token = new ResetPasswordToken('raw-token', new \DateTimeImmutable('+1 hour'), (new \DateTimeImmutable('+1 hour'))->getTimestamp());

        $notifier->sendResetLink($user, $token);

        self::assertNotNull($captured);
        self::assertSame('Nulstil Curator', $captured->getSubject());
        self::assertSame('sender@example.test', $captured->getFrom()[0]->getAddress());
        self::assertSame('curator@example.test', $captured->getTo()[0]->getAddress());
    }

    // Verifies requestReset returns null and skips the mailer send when the bundle rejects a fresh token — either the throttling window is still open or an unexpired outstanding request exists.
    public function testRequestResetReturnsNullWhenHelperRefusesToken(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $helper = $this->createMock(ResetPasswordHelperInterface::class);
        $helper->expects(self::once())
            ->method('generateResetToken')
            ->willThrowException(new TooManyPasswordRequestsException(new \DateTimeImmutable('+1 hour')));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $notifier = new PasswordResetNotifier(
            $mailer,
            $this->createStub(SettingsManager::class),
            new EmailTemplateRenderer(new CommonMarkConverter()),
            $helper,
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(TranslatorInterface::class),
            $logger,
        );

        $user = (new User())->setEmail('curator@example.test');

        self::assertNull($notifier->requestReset($user));
    }

    // Verifies requestReset returns the minted token and dispatches one message when the helper accepts the request.
    public function testRequestResetReturnsMintedTokenAndSendsEmail(): void
    {
        $captured = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->willReturnCallback(static function (Email $email) use (&$captured): void {
                $captured = $email;
            });

        $token = new ResetPasswordToken('raw-token', new \DateTimeImmutable('+1 hour'), (new \DateTimeImmutable('+1 hour'))->getTimestamp());

        $helper = $this->createMock(ResetPasswordHelperInterface::class);
        $helper->expects(self::once())
            ->method('generateResetToken')
            ->willReturn($token);

        $settings = $this->createStub(SettingsManager::class);
        $settings->method('getSenderAddress')->willReturn('sender@example.test');
        $settings->method('getPasswordResetSubject')->willReturn('Nulstil');
        $settings->method('getPasswordResetBody')->willReturn('Body');
        $settings->method('getBrandName')->willReturn('AI-reolen');

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://x/reset/abc');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('60 minutter');

        $notifier = new PasswordResetNotifier(
            $mailer,
            $settings,
            new EmailTemplateRenderer(new CommonMarkConverter()),
            $helper,
            $urls,
            $translator,
            $this->createMock(LoggerInterface::class),
        );

        $user = (new User())->setEmail('curator@example.test');
        $user->setName('Curator');

        $returned = $notifier->requestReset($user);

        self::assertSame($token, $returned);
        self::assertNotNull($captured);
    }
}
