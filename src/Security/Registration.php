<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Notification\EmailConfirmationNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Public-signup orchestration.
 *
 * Sits between {@see \App\Controller\RegistrationController} and the
 * existing {@see UserManager}, owning the rules that distinguish a
 * legitimate self-signup attempt from one that should be rejected.
 *
 * 1. Two anti-flood checks — a per-IP and a system-wide rate
 *    limiter — gate the entrance.
 * 2. The submitted email must syntactically parse as an email.
 * 3. The right-hand side of the email must be on the allow-list
 *    {@see AllowedEmailDomains}.
 * 4. The two password fields must match.
 * 5. The name must be non-empty (rule shared with {@see UserManager}).
 *
 * On success the new {@see User} is persisted with
 * `status = AwaitingEmailConfirmation` and exactly one transactional
 * email is dispatched: the single-use confirmation link that flips
 * the status to `Pending` once clicked. The moderator notification
 * and the user welcome mail are deliberately deferred to
 * {@see EmailConfirmation::consume()} — nothing lands in the
 * moderator inbox and nothing welcomes the user until the address
 * has been verified. The {@see AccountStatusChecker}
 * keeps the user out of the login flow at every status below
 * `Approved`, so the moderator queue still gates site access
 * regardless of whether the user has confirmed their email yet.
 *
 * On a duplicate e-mail the method is intentionally idempotent — it
 * returns `null` rather than throwing, so the controller can render
 * the same response and no information leaks about whether the
 * address is already registered.
 */
final class Registration
{
    /**
     * @param UserManager                 $userManager            owns the persistence + password-hashing step
     * @param AllowedEmailDomains         $allowedEmailDomains    domain allow-list parsed from the env var
     * @param EmailConfirmationNotifier   $emailLinkNotifier      fires the single-use email-confirmation link
     * @param LoggerInterface             $logger                 receives a warning on transient mailer failures
     * @param RateLimiterFactoryInterface $registrationPerIp      per-IP rate limiter (10/day by default)
     * @param RateLimiterFactoryInterface $registrationSystemWide system-wide rate limiter (100/day by default)
     */
    public function __construct(
        private readonly UserManager $userManager,
        private readonly AllowedEmailDomains $allowedEmailDomains,
        private readonly EmailConfirmationNotifier $emailLinkNotifier,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.registration_per_ip')]
        private readonly RateLimiterFactoryInterface $registrationPerIp,
        #[Autowire(service: 'limiter.registration_system_wide')]
        private readonly RateLimiterFactoryInterface $registrationSystemWide,
    ) {
    }

    /**
     * Run the self-signup pipeline and persist an `AwaitingEmailConfirmation` user.
     *
     * The thrown exceptions carry localised translation keys; the
     * controller uses them as the rendered form error. A duplicate
     * e-mail submission falls through with a `null` return so the
     * caller renders the same response as a fresh registration.
     *
     * @param string $clientIp             remote IP, scoped per request; used as the per-IP limiter key
     * @param string $email                submitted email; must be valid + on the allow-list
     * @param string $name                 display name; trimmed by {@see UserManager}
     * @param string $plainPassword        chosen password
     * @param string $plainPasswordConfirm confirmation field; must match `$plainPassword`
     *
     * @return User|null the persisted user with `status = AwaitingEmailConfirmation`, or null when the e-mail is already registered
     *
     * @throws RateLimitedRegistrationException when either the per-IP or system-wide limiter rejects the request
     * @throws RegistrationException            when any of the inputs fails validation
     */
    public function register(
        string $clientIp,
        string $email,
        string $name,
        string $plainPassword,
        string $plainPasswordConfirm,
    ): ?User {
        $this->checkRateLimits($clientIp);

        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new RegistrationException('register.error.invalid_email');
        }

        // `filter_var` above guarantees the email has a non-trailing `@`,
        // so `strrpos` returns an int and the substr is the domain. See
        // the file-level comment for why this is inlined instead of
        // calling App\Security\EmailDomain::of().
        $domain = strtolower(substr($email, (int) strrpos($email, '@') + 1));
        if (!$this->allowedEmailDomains->contains($domain)) {
            throw new RegistrationException('register.error.domain_not_allowed');
        }

        if ($plainPassword !== $plainPasswordConfirm) {
            throw new RegistrationException('register.error.password_mismatch');
        }

        if ('' === trim($name)) {
            throw new RegistrationException('register.error.empty_name');
        }

        if ('' === $plainPassword) {
            throw new RegistrationException('register.error.empty_password');
        }

        try {
            $user = $this->userManager->createUser(
                $email,
                trim($name),
                $plainPassword,
                status: UserStatus::AwaitingEmailConfirmation,
            );
        } catch (\DomainException) {
            // Idempotent: same outward response as a fresh registration
            // so a probe can't learn whether the e-mail is already taken.
            return null;
        }

        $this->dispatchNotifications($user);

        return $user;
    }

    /**
     * Consume one token from each registration limiter, throwing when either is exhausted.
     *
     * Per-IP is keyed on the supplied address; system-wide uses a
     * single shared bucket so a coordinated burst across many IPs
     * still hits a ceiling.
     *
     * @param string $clientIp remote IP, used as the per-IP limiter key
     *
     * @throws RateLimitedRegistrationException when either bucket is exhausted
     */
    private function checkRateLimits(string $clientIp): void
    {
        $perIp = $this->registrationPerIp->create($clientIp);
        if (!$perIp->consume()->isAccepted()) {
            throw new RateLimitedRegistrationException('register.error.rate_limited');
        }

        $systemWide = $this->registrationSystemWide->create('global');
        if (!$systemWide->consume()->isAccepted()) {
            throw new RateLimitedRegistrationException('register.error.rate_limited');
        }
    }

    /**
     * Fire the single transactional email triggered by a fresh signup.
     *
     * Only the confirmation link goes out here — the moderator
     * notification and user welcome mail are deferred to
     * {@see EmailConfirmation::consume()} so nothing hits either
     * recipient before the address is verified. The send is wrapped
     * in its own try/catch around the mailer transport — a transient
     * delivery failure must not undo the persisted user (a fresh
     * confirmation link can be re-issued), so failures are logged
     * and swallowed.
     *
     * @param User $user the freshly-created `AwaitingEmailConfirmation` user
     */
    private function dispatchNotifications(User $user): void
    {
        try {
            $this->emailLinkNotifier->sendConfirmationLink($user);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Failed to deliver email-confirmation link mail.', [
                'user_email' => $user->getUserIdentifier(),
                'exception' => $e,
            ]);
        }
    }
}
