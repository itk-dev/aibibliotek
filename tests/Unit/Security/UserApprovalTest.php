<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Notification\RegistrationConfirmationNotifier;
use App\Repository\UserRepository;
use App\Security\UserApproval;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Unit-level coverage of the notifier try/catch branch in
 * {@see UserApproval::approve()}.
 *
 * The integration test in {@see \App\Tests\Integration\Security\UserApprovalTest}
 * drives the happy path with a real notifier dispatching a real message; this
 * suite complements it by exercising the defensive branch where the mailer
 * transport throws — the status transition must still succeed.
 */
final class UserApprovalTest extends TestCase
{
    // Verifies a transport failure on the confirmation notifier is logged and swallowed, and the status transition still succeeds.
    public function testApproveSwallowsTransportFailureOnConfirmationNotifier(): void
    {
        $user = new User();
        $user->setEmail('carol@example.test');
        $user->setName('Carol');
        $user->setStatus(UserStatus::Pending);

        $confirmationNotifier = $this->createMock(RegistrationConfirmationNotifier::class);
        $confirmationNotifier->method('confirmRegistration')
            ->willThrowException(new TransportException('SMTP down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('approval confirmation mail'));

        $service = new UserApproval(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(UserRepository::class),
            $confirmationNotifier,
            $logger,
        );

        $service->approve($user);

        self::assertSame(UserStatus::Approved, $user->getStatus());
    }
}
