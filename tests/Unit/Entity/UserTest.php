<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Enum\UserStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class UserTest extends TestCase
{
    // Tests that a freshly-constructed User defaults to Pending status and an empty name.
    public function testConstructorDefaultsStatusToPending(): void
    {
        $user = new User();

        self::assertSame(UserStatus::Pending, $user->getStatus());
        self::assertSame('', $user->getName());
    }

    // Ensures ROLE_USER is always present in getRoles() output, even when not set explicitly.
    public function testGetRolesAlwaysIncludesRoleUser(): void
    {
        $user = new User();

        self::assertSame(['ROLE_USER'], $user->getRoles());

        $user->setRoles(['ROLE_ADMIN']);
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    // Ensures duplicate ROLE_USER entries are deduplicated in getRoles().
    public function testGetRolesDeduplicatesRoleUserWhenAlreadyPresent(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);

        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
    }

    // Tests that getUserIdentifier returns '' when no email has been set.
    public function testGetUserIdentifierReturnsEmptyStringWhenEmailIsNull(): void
    {
        $user = new User();

        self::assertSame('', $user->getUserIdentifier());
    }

    // Tests that getUserIdentifier returns the email when set.
    public function testGetUserIdentifierReturnsEmailWhenSet(): void
    {
        $user = new User();
        $user->setEmail('alice@example.test');

        self::assertSame('alice@example.test', $user->getUserIdentifier());
    }

    // Verifies __serialize replaces the password hash with a CRC32C hash so the session never carries the original.
    public function testSerializeReplacesPasswordWithCrc32cHash(): void
    {
        $user = new User();
        $user->setEmail('alice@example.test');
        $user->setPassword('plaintext-hash');

        $data = $user->__serialize();

        $passwordKey = "\0".User::class."\0password";
        self::assertArrayHasKey($passwordKey, $data);
        self::assertSame(hash('crc32c', 'plaintext-hash'), $data[$passwordKey]);
        self::assertNotContains('plaintext-hash', $data, 'Serialised payload must not contain the original password hash.');
    }

    // Verifies the AwaitingEmailConfirmation case round-trips through setStatus()/getStatus() (issue #103).
    public function testAwaitingEmailConfirmationStatusRoundTrips(): void
    {
        $user = new User();
        $user->setStatus(UserStatus::AwaitingEmailConfirmation);

        self::assertSame(UserStatus::AwaitingEmailConfirmation, $user->getStatus());
        self::assertSame('awaiting_email_confirmation', $user->getStatus()->value);
    }

    // Tests that every setter returns $this (fluent) and mutates the underlying value.
    public function testSettersMutateAndReturnStatic(): void
    {
        $user = new User();

        self::assertSame($user, $user->setEmail('bob@example.test'));
        self::assertSame('bob@example.test', $user->getEmail());

        self::assertSame($user, $user->setPassword('hashed-password'));
        self::assertSame('hashed-password', $user->getPassword());

        self::assertSame($user, $user->setRoles(['ROLE_EDITOR']));
        self::assertSame(['ROLE_EDITOR', 'ROLE_USER'], $user->getRoles());

        self::assertSame($user, $user->setName('Bob'));
        self::assertSame('Bob', $user->getName());

        self::assertSame($user, $user->setStatus(UserStatus::Approved));
        self::assertSame(UserStatus::Approved, $user->getStatus());

        self::assertInstanceOf(Ulid::class, $user->getId());
    }
}
