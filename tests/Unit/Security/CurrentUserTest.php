<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\CurrentUser;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The guard this covers replaced `\assert($user instanceof User)` at
 * five call sites. Those asserts never executed — both container images
 * set `zend.assertions=-1` — so these are the first tests that prove
 * the narrowing actually holds.
 */
final class CurrentUserTest extends TestCase
{
    // Tests that an authenticated App user is returned unchanged.
    public function testReturnsTheAuthenticatedUser(): void
    {
        $user = (new User())->setEmail('alice@example.test')->setName('Alice');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        self::assertSame($user, (new CurrentUser($security))->get());
    }

    // Ensures an unauthenticated request raises rather than returning null to the caller.
    public function testThrowsWhenNobodyIsAuthenticated(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Expected an authenticated');

        (new CurrentUser($security))->get();
    }

    // Ensures a token holding some other UserInterface is rejected, not silently accepted.
    public function testThrowsWhenTheTokenHoldsAnotherUserType(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($this->createMock(UserInterface::class));

        $this->expectException(\LogicException::class);

        (new CurrentUser($security))->get();
    }
}
