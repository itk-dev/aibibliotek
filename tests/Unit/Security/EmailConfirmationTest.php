<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Notification\AdminRegistrationNotifier;
use App\Notification\DomainRegistrationNotifier;
use App\Notification\RegistrationConfirmationNotifier;
use App\Repository\UserRepository;
use App\Security\EmailConfirmation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Unit-level coverage of the notifier try/catch paths in
 * {@see EmailConfirmation::consume()}.
 *
 * The integration test in {@see \App\Tests\Integration\Security\EmailConfirmationTest}
 * drives the happy path with real notifiers dispatching real messages;
 * this suite complements it by exercising the defensive branches where
 * either notifier's mailer transport throws — the transition must still
 * succeed and the caller must still get the user back.
 */
final class EmailConfirmationTest extends TestCase
{
    // Verifies a transport failure on the admin notifier is logged, swallowed, and doesn't block the welcome notifier or the return value.
    public function testConsumeSwallowsTransportFailureOnAdminNotifier(): void
    {
        $user = $this->makeAwaitingUser();

        $adminNotifier = $this->createMock(AdminRegistrationNotifier::class);
        $adminNotifier->method('notifyOfNewRegistration')
            ->willThrowException(new TransportException('SMTP down'));

        $confirmationNotifier = $this->createMock(RegistrationConfirmationNotifier::class);
        // Even though admin dispatch failed, the welcome mail must still fire.
        $confirmationNotifier->expects(self::once())->method('confirmRegistration');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('admin registration notification'));

        $service = new EmailConfirmation(
            $this->makeCacheHitting($user),
            $this->createMock(EntityManagerInterface::class),
            $this->makeRepositoryReturning($user),
            $adminNotifier,
            $this->createMock(DomainRegistrationNotifier::class),
            $confirmationNotifier,
            $logger,
        );

        $result = $service->consume('any-token');

        self::assertSame($user, $result);
    }

    // Verifies a transport failure on the domain notifier is logged and swallowed so the welcome notifier still fires.
    public function testConsumeSwallowsTransportFailureOnDomainNotifier(): void
    {
        $user = $this->makeAwaitingUser();

        $adminNotifier = $this->createMock(AdminRegistrationNotifier::class);
        $adminNotifier->expects(self::once())->method('notifyOfNewRegistration');

        $domainNotifier = $this->createMock(DomainRegistrationNotifier::class);
        $domainNotifier->method('notifyOfNewRegistration')
            ->willThrowException(new TransportException('SMTP down'));

        $confirmationNotifier = $this->createMock(RegistrationConfirmationNotifier::class);
        // Even though the domain dispatch failed, the welcome mail must still fire.
        $confirmationNotifier->expects(self::once())->method('confirmRegistration');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('domain registration notification'));

        $service = new EmailConfirmation(
            $this->makeCacheHitting($user),
            $this->createMock(EntityManagerInterface::class),
            $this->makeRepositoryReturning($user),
            $adminNotifier,
            $domainNotifier,
            $confirmationNotifier,
            $logger,
        );

        $result = $service->consume('any-token');

        self::assertSame($user, $result);
    }

    // Verifies a transport failure on the welcome notifier is logged and swallowed after the admin notifier has already fired.
    public function testConsumeSwallowsTransportFailureOnConfirmationNotifier(): void
    {
        $user = $this->makeAwaitingUser();

        $adminNotifier = $this->createMock(AdminRegistrationNotifier::class);
        $adminNotifier->expects(self::once())->method('notifyOfNewRegistration');

        $confirmationNotifier = $this->createMock(RegistrationConfirmationNotifier::class);
        $confirmationNotifier->method('confirmRegistration')
            ->willThrowException(new TransportException('SMTP down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('registration confirmation mail'));

        $service = new EmailConfirmation(
            $this->makeCacheHitting($user),
            $this->createMock(EntityManagerInterface::class),
            $this->makeRepositoryReturning($user),
            $adminNotifier,
            $this->createMock(DomainRegistrationNotifier::class),
            $confirmationNotifier,
            $logger,
        );

        $result = $service->consume('any-token');

        self::assertSame($user, $result);
    }

    /**
     * Build a fresh `AwaitingEmailConfirmation` user for the
     * consume-path tests. The status property is what `consume()`
     * gates on, so the value here matters.
     */
    private function makeAwaitingUser(): User
    {
        $user = new User();
        $user->setEmail('carol@example.test');
        $user->setName('Carol');
        $user->setStatus(UserStatus::AwaitingEmailConfirmation);

        return $user;
    }

    /**
     * Build a cache pool that pretends the token hits, returns the
     * user's id, and accepts deleteItem() without complaint.
     */
    private function makeCacheHitting(User $user): CacheItemPoolInterface
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn((string) $user->getId());

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);
        $pool->method('deleteItem')->willReturn(true);

        return $pool;
    }

    /**
     * Build a `UserRepository` that returns the supplied user for
     * any `find()` call — the consume path only looks up by id.
     */
    private function makeRepositoryReturning(User $user): UserRepository
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('find')->willReturn($user);

        return $repository;
    }
}
