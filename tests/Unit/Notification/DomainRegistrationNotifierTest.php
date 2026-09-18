<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Mail\EmailTemplateRenderer;
use App\Notification\DomainRegistrationNotifier;
use App\Repository\UserRepository;
use App\Security\Roles;
use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Unit-level coverage of the per-recipient try/catch branch in
 * {@see DomainRegistrationNotifier}.
 *
 * The integration test in
 * {@see \App\Tests\Integration\Notification\DomainNotifierTest}
 * drives the happy path against the null mailer transport and the
 * real repository; this suite complements it by simulating a
 * transport failure on one recipient and verifying the loop keeps
 * going for the remaining recipients — a single flaky mailbox
 * must not block delivery to their peers.
 */
final class DomainRegistrationNotifierTest extends TestCase
{
    // Verifies a transport failure on one recipient is logged and swallowed so the next recipient still receives their mail.
    public function testTransportFailureOnOneRecipientDoesNotAbortTheLoop(): void
    {
        $sentTo = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::exactly(2))
            ->method('send')
            ->willReturnCallback(function (\Symfony\Component\Mime\Email $email) use (&$sentTo): void {
                $recipient = $email->getTo()[0]->getAddress();
                $sentTo[] = $recipient;
                if ('flaky@aarhus.dk' === $recipient) {
                    throw new TransportException('SMTP down for flaky');
                }
            });

        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getSenderAddress')->willReturn('sender@example.test');
        $settings->method('getAdminNotificationSubject')->willReturn('Ny bruger: %name%');
        $settings->method('getAdminNotificationBody')->willReturn('E-mail: %email%');
        $settings->method('getBrandName')->willReturn('Brand');

        $flaky = $this->makeApprover('flaky@aarhus.dk', 'Flaky', Roles::DOMAIN_MANAGER);
        $healthy = $this->makeApprover('healthy@aarhus.dk', 'Healthy', Roles::ADMIN);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findApproversForDomain')
            ->with('aarhus.dk')
            ->willReturn([$flaky, $healthy]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('domain registration notification'));

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.test/admin/users?status=pending');

        $notifier = new DomainRegistrationNotifier(
            $mailer,
            $settings,
            new EmailTemplateRenderer(new \League\CommonMark\CommonMarkConverter()),
            $urlGenerator,
            $userRepository,
            $logger,
        );

        $notifier->notifyOfNewRegistration($this->makeNewUser('newbie@aarhus.dk', 'Newbie'));

        self::assertSame(['flaky@aarhus.dk', 'healthy@aarhus.dk'], $sentTo);
    }

    /**
     * Build an unpersisted Approved approver (manager or admin) for
     * the notifier's per-recipient loop.
     */
    private function makeApprover(string $email, string $name, string $role): User
    {
        return (new User())
            ->setEmail($email)
            ->setName($name)
            ->setRoles([$role])
            ->setStatus(UserStatus::Approved);
    }

    /**
     * Build the newly-confirmed user the notifier is dispatching for.
     */
    private function makeNewUser(string $email, string $name): User
    {
        return (new User())
            ->setEmail($email)
            ->setName($name)
            ->setStatus(UserStatus::Pending);
    }
}
