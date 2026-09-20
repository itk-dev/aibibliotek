<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Security\AccountStatusChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserInterface;

final class AccountStatusCheckerTest extends TestCase
{
    // Tests that an Approved user passes the pre-auth hook without raising.
    public function testApprovedUserPassesPreAuth(): void
    {
        $user = new User()
            ->setName('Alice')
            ->setStatus(UserStatus::Approved);

        // No exception thrown is the assertion.
        $this->expectNotToPerformAssertions();

        new AccountStatusChecker()->checkPreAuth($user);
    }

    // Ensures an AwaitingEmailConfirmation user is rejected with the 'account.awaiting_email_confirmation' message key (issue #103).
    public function testAwaitingEmailConfirmationUserIsRejectedWithLocalisedMessage(): void
    {
        $user = new User()
            ->setName('Awaiting')
            ->setStatus(UserStatus::AwaitingEmailConfirmation);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('account.awaiting_email_confirmation');

        new AccountStatusChecker()->checkPreAuth($user);
    }

    // Ensures a Pending user is rejected with the 'account.pending' message key.
    public function testPendingUserIsRejectedWithLocalisedMessage(): void
    {
        $user = new User()
            ->setName('Pending')
            ->setStatus(UserStatus::Pending);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('account.pending');

        new AccountStatusChecker()->checkPreAuth($user);
    }

    // Ensures a Blocked user is rejected with the 'account.blocked' message key.
    public function testBlockedUserIsRejectedWithLocalisedMessage(): void
    {
        $user = new User()
            ->setName('Blocked')
            ->setStatus(UserStatus::Blocked);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('account.blocked');

        new AccountStatusChecker()->checkPreAuth($user);
    }

    // Verifies non-App User implementations fall through to the password checker.
    public function testForeignUserImplementationsAreIgnored(): void
    {
        $foreignUser = $this->createStub(UserInterface::class);

        // Falling through without raising is the assertion.
        $this->expectNotToPerformAssertions();

        new AccountStatusChecker()->checkPreAuth($foreignUser);
    }

    // Tests that checkPostAuth does nothing (required by the interface).
    public function testCheckPostAuthIsANoOp(): void
    {
        $user = new User()->setStatus(UserStatus::Approved);

        // Doing nothing is the assertion.
        $this->expectNotToPerformAssertions();

        new AccountStatusChecker()->checkPostAuth($user);
    }
}
