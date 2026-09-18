<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Mail\EmailTemplateRenderer;
use App\Notification\AdminRegistrationNotifier;
use App\Notification\DomainRegistrationNotifier;
use App\Notification\EmailConfirmationNotifier;
use App\Notification\RegistrationConfirmationNotifier;
use App\Repository\UserRepository;
use App\Security\EmailConfirmation;
use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Both registration notifiers must skip the send (not throw) when
 * {@see SettingsManager::getSenderAddress()} returns `null`, so the
 * registration request stays alive on an unconfigured `From:`.
 */
final class NotifierSkipsWhenSenderUnsetTest extends TestCase
{
    // Ensures AdminRegistrationNotifier skips the send when the sender address is null.
    public function testAdminNotifierSkipsWhenSenderUnset(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getAdminRecipient')->willReturn('ops@example.test');
        $settings->method('getSenderAddress')->willReturn(null);

        $notifier = new AdminRegistrationNotifier(
            $mailer,
            $settings,
            new EmailTemplateRenderer(new \League\CommonMark\CommonMarkConverter()),
            $this->createMock(UrlGeneratorInterface::class),
            new NullLogger(),
        );

        $notifier->notifyOfNewRegistration($this->makeUser());
    }

    // Ensures DomainRegistrationNotifier skips the send when the sender address is null.
    public function testDomainNotifierSkipsWhenSenderUnset(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getSenderAddress')->willReturn(null);

        // The repository is never queried when the sender check
        // short-circuits — expect zero interactions.
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::never())->method('findApproversForDomain');

        $notifier = new DomainRegistrationNotifier(
            $mailer,
            $settings,
            new EmailTemplateRenderer(new \League\CommonMark\CommonMarkConverter()),
            $this->createMock(UrlGeneratorInterface::class),
            $userRepository,
            new NullLogger(),
        );

        $notifier->notifyOfNewRegistration($this->makeUser());
    }

    // Ensures RegistrationConfirmationNotifier skips the send when the sender address is null.
    public function testConfirmationNotifierSkipsWhenSenderUnset(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getSenderAddress')->willReturn(null);

        $notifier = new RegistrationConfirmationNotifier(
            $mailer,
            $settings,
            new EmailTemplateRenderer(new \League\CommonMark\CommonMarkConverter()),
            new NullLogger(),
        );

        $notifier->confirmRegistration($this->makeUser());
    }

    // Ensures EmailConfirmationNotifier skips the send when the sender address is null, so a missing MAILER_FROM doesn't break the registration flow.
    public function testEmailConfirmationNotifierSkipsWhenSenderUnset(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getSenderAddress')->willReturn(null);

        // EmailConfirmation is final, so we wire a real one with mock
        // collaborators that aren't expected to be called — the notifier
        // returns before issueToken() is ever invoked when the sender
        // is unset.
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::never())->method('getItem');

        $notifier = new EmailConfirmationNotifier(
            $mailer,
            $settings,
            new EmailTemplateRenderer(new \League\CommonMark\CommonMarkConverter()),
            new EmailConfirmation(
                $cache,
                $this->createMock(EntityManagerInterface::class),
                $this->createMock(UserRepository::class),
                $this->createMock(AdminRegistrationNotifier::class),
                $this->createMock(DomainRegistrationNotifier::class),
                $this->createMock(RegistrationConfirmationNotifier::class),
                new NullLogger(),
            ),
            $this->createMock(UrlGeneratorInterface::class),
            new NullLogger(),
        );

        $notifier->sendConfirmationLink($this->makeUser());
    }

    private function makeUser(): User
    {
        return (new User())
            ->setEmail('carol@example.test')
            ->setName('Carol')
            ->setStatus(UserStatus::AwaitingEmailConfirmation);
    }
}
