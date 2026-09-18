<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Notification\EmailConfirmationNotifier;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Security\AllowedEmailDomains;
use App\Security\RateLimitedRegistrationException;
use App\Security\Registration;
use App\Security\RegistrationException;
use App\Security\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\Policy\NoLimiter;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class RegistrationTest extends TestCase
{
    private const string CLIENT_IP = '203.0.113.7';

    // Ensures malformed emails are rejected with the invalid_email translation key.
    public function testRejectsInvalidEmail(): void
    {
        $reg = $this->registration(allowList: 'example.test');

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('register.error.invalid_email');

        $reg->register(self::CLIENT_IP, 'not-an-email', 'Carol', 'secret', 'secret');
    }

    // Ensures emails outside the allow-list raise domain_not_allowed.
    public function testRejectsDomainNotOnAllowList(): void
    {
        $reg = $this->registration(allowList: 'aarhus.dk');

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('register.error.domain_not_allowed');

        $reg->register(self::CLIENT_IP, 'carol@example.test', 'Carol', 'secret', 'secret');
    }

    // Ensures mismatched password + confirmation raise password_mismatch.
    public function testRejectsPasswordMismatch(): void
    {
        $reg = $this->registration(allowList: 'example.test');

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('register.error.password_mismatch');

        $reg->register(self::CLIENT_IP, 'carol@example.test', 'Carol', 'secret', 'different');
    }

    // Ensures whitespace-only names raise empty_name.
    public function testRejectsEmptyName(): void
    {
        $reg = $this->registration(allowList: 'example.test');

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('register.error.empty_name');

        $reg->register(self::CLIENT_IP, 'carol@example.test', '   ', 'secret', 'secret');
    }

    // Ensures empty passwords raise empty_password.
    public function testRejectsEmptyPassword(): void
    {
        $reg = $this->registration(allowList: 'example.test');

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('register.error.empty_password');

        $reg->register(self::CLIENT_IP, 'carol@example.test', 'Carol', '', '');
    }

    // Verifies a duplicate-email submission is idempotent: returns null without throwing, never reaches persist().
    public function testDuplicateEmailReturnsNullWithoutPersisting(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(UserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);

        // UserManager's duplicate-email path returns the existing user
        // from the repository on the pre-flight findOneBy lookup.
        $repo->method('findOneBy')->willReturn(new User());

        // Idempotent path must NOT persist or flush.
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $reg = new Registration(
            new UserManager($em, $repo, $hasher),
            $this->allowedDomains(['example.test']),
            $this->createMock(EmailConfirmationNotifier::class),
            new NullLogger(),
            $this->openLimiter(),
            $this->openLimiter(),
        );

        $result = $reg->register(self::CLIENT_IP, 'carol@example.test', 'Carol', 'secret', 'secret');

        self::assertNull($result);
    }

    // Tests the happy path: valid submission persists an AwaitingEmailConfirmation user with trimmed name and hashed password, and dispatches only the confirmation-link mail.
    public function testPersistsAwaitingEmailConfirmationUserOnHappyPath(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(UserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);

        $repo->method('findOneBy')->willReturn(null);
        $hasher->method('hashPassword')->willReturn('hashed-secret');

        $captured = null;
        $em->expects(self::once())
            ->method('persist')
            ->willReturnCallback(function (object $entity) use (&$captured): void {
                \assert($entity instanceof User);
                $captured = $entity;
            });
        $em->expects(self::once())->method('flush');

        $emailLinkNotifier = $this->createMock(EmailConfirmationNotifier::class);
        // Only the confirmation-link mail fires on signup. The
        // moderator + welcome mails have moved to
        // EmailConfirmation::consume() so they only run once the
        // address is verified.
        $emailLinkNotifier->expects(self::once())->method('sendConfirmationLink');

        $reg = new Registration(
            new UserManager($em, $repo, $hasher),
            $this->allowedDomains(['example.test']),
            $emailLinkNotifier,
            new NullLogger(),
            $this->openLimiter(),
            $this->openLimiter(),
        );

        $user = $reg->register(self::CLIENT_IP, 'Carol@Example.test', '  Carol  ', 'secret', 'secret');

        self::assertNotNull($user);
        self::assertSame($user, $captured);
        self::assertSame('Carol@Example.test', $user->getEmail());
        self::assertSame('Carol', $user->getName(), 'Name is trimmed before persistence.');
        self::assertSame(UserStatus::AwaitingEmailConfirmation, $user->getStatus());
        self::assertSame('hashed-secret', $user->getPassword());
    }

    // Verifies a transport failure on the confirmation-link mail is logged but doesn't undo the persisted user.
    public function testNotifierTransportFailureIsSwallowed(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(UserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $repo->method('findOneBy')->willReturn(null);
        $hasher->method('hashPassword')->willReturn('hashed-secret');

        $emailLinkNotifier = $this->createMock(EmailConfirmationNotifier::class);
        $emailLinkNotifier->method('sendConfirmationLink')
            ->willThrowException(new TransportException('SMTP down'));

        $reg = new Registration(
            new UserManager($em, $repo, $hasher),
            $this->allowedDomains(['example.test']),
            $emailLinkNotifier,
            new NullLogger(),
            $this->openLimiter(),
            $this->openLimiter(),
        );

        // No exception leaks out — the persisted user comes back even though
        // the transport send failed.
        $user = $reg->register(self::CLIENT_IP, 'carol@example.test', 'Carol', 'secret', 'secret');
        self::assertInstanceOf(User::class, $user);
    }

    // Verifies the per-IP limiter rejects the request before any validation runs.
    public function testRejectsWhenPerIpLimitExhausted(): void
    {
        $reg = new Registration(
            $this->buildUserManager(),
            $this->allowedDomains(['example.test']),
            $this->createMock(EmailConfirmationNotifier::class),
            new NullLogger(),
            $this->closedLimiter(),
            $this->openLimiter(),
        );

        $this->expectException(RateLimitedRegistrationException::class);
        $this->expectExceptionMessage('register.error.rate_limited');

        $reg->register(self::CLIENT_IP, 'carol@example.test', 'Carol', 'secret', 'secret');
    }

    // Verifies the system-wide limiter rejects even when the per-IP limiter still has room.
    public function testRejectsWhenSystemWideLimitExhausted(): void
    {
        $reg = new Registration(
            $this->buildUserManager(),
            $this->allowedDomains(['example.test']),
            $this->createMock(EmailConfirmationNotifier::class),
            new NullLogger(),
            $this->openLimiter(),
            $this->closedLimiter(),
        );

        $this->expectException(RateLimitedRegistrationException::class);
        $this->expectExceptionMessage('register.error.rate_limited');

        $reg->register(self::CLIENT_IP, 'carol@example.test', 'Carol', 'secret', 'secret');
    }

    /**
     * Build a Registration with mock collaborators that never reach
     * the persistence step. Limiters are no-op so the validation
     * branches under test always run.
     */
    private function registration(string $allowList): Registration
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(UserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);

        return new Registration(
            new UserManager($em, $repo, $hasher),
            $this->allowedDomains([$allowList]),
            $this->createMock(EmailConfirmationNotifier::class),
            new NullLogger(),
            $this->openLimiter(),
            $this->openLimiter(),
        );
    }

    /**
     * Build an {@see AllowedEmailDomains} around a stubbed
     * `OrganizationRepository` whose `collectAllowedEmailDomains()`
     * returns the supplied list verbatim. Lets the unit test pick
     * the allow-list without spinning up Doctrine.
     *
     * @param list<string> $domains the canned allow-list
     */
    private function allowedDomains(array $domains): AllowedEmailDomains
    {
        $repository = $this->createMock(OrganizationRepository::class);
        $repository->method('collectAllowedEmailDomains')->willReturn($domains);

        return new AllowedEmailDomains($repository);
    }

    /**
     * Build a real `UserManager` with mocked collaborators so the
     * rate-limit guard can short-circuit before persistence runs.
     */
    private function buildUserManager(): UserManager
    {
        return new UserManager(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(UserRepository::class),
            $this->createMock(UserPasswordHasherInterface::class),
        );
    }

    /**
     * Build a `RateLimiterFactoryInterface` that always accepts —
     * Symfony ships {@see NoLimiter} for exactly this case.
     */
    private function openLimiter(): RateLimiterFactoryInterface
    {
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn(new NoLimiter());

        return $factory;
    }

    /**
     * Build a `RateLimiterFactoryInterface` whose `consume()` is
     * always rejected, so the registration rate-limit guard trips.
     */
    private function closedLimiter(): RateLimiterFactoryInterface
    {
        $rejected = new RateLimit(0, new \DateTimeImmutable('+1 hour'), false, 1);
        $limiter = $this->createMock(\Symfony\Component\RateLimiter\LimiterInterface::class);
        $limiter->method('consume')->willReturn($rejected);

        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        return $factory;
    }
}
