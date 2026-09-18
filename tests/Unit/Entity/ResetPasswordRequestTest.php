<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Guards the small accessors on {@see ResetPasswordRequest}.
 *
 * The bundle-supplied `ResetPasswordRequestTrait` covers the
 * bulk of the class (selector, hashedToken, expiresAt) via its
 * own tests; the two accessors we add here (the FK to `User`,
 * the primary-key id) are the pieces that could regress if the
 * scaffold ever gets re-generated with a different mapping.
 */
final class ResetPasswordRequestTest extends TestCase
{
    // Tests the constructor stores the user, and getUser() returns it.
    public function testConstructorStoresUser(): void
    {
        $user = new User();
        $request = new ResetPasswordRequest(
            $user,
            new \DateTimeImmutable('+1 hour'),
            'selector',
            'hashed-token-value',
        );

        self::assertSame($user, $request->getUser());
    }

    // Verifies getId() returns null until the entity has been flushed and assigned an autoincrement id.
    public function testGetIdReturnsNullBeforePersist(): void
    {
        $request = new ResetPasswordRequest(
            new User(),
            new \DateTimeImmutable('+1 hour'),
            'selector',
            'hashed-token-value',
        );

        self::assertNull($request->getId());
    }
}
