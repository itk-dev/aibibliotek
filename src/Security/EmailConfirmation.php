<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Notification\AdminRegistrationNotifier;
use App\Notification\DomainRegistrationNotifier;
use App\Notification\RegistrationConfirmationNotifier;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Owns the single-use confirmation token a newly-registered user must
 * present to leave `UserStatus::AwaitingEmailConfirmation`.
 *
 * The token itself is a random base-64-url string (no embedded user
 * id — the cache row is the only mapping from token to user). It
 * lives in the dedicated `cache.email_confirmation` pool with a
 * fixed 24-hour TTL. {@see consume()} reads the row, transitions
 * the user's status to `Pending`, deletes the row, and dispatches
 * the two follow-up notifications (moderator inbox + user
 * welcome). The deletion is what makes the token single-use, the
 * TTL caps the lifetime if it never gets clicked.
 *
 * Follow-up mails only fire when the transition actually happens —
 * an already-consumed or unknown token returns `null` and sends
 * nothing, so a second click can't spam either recipient.
 *
 * The status transition is the *only* auth effect of consuming a
 * token: the user is not authenticated, no session is established.
 * The caller (a public-route controller) is expected to render a
 * confirmation page and leave login to the form-login flow after a
 * moderator approves the user. This matches the project's "Pending
 * users have no site access" rule documented in ADR 006.
 */
final class EmailConfirmation
{
    /**
     * Lifetime of an issued confirmation token, in seconds. A
     * generous 24 hours so a user who registers late at night can
     * still click the link the next morning without re-triggering
     * the registration flow.
     */
    public const int TOKEN_TTL_SECONDS = 86400;

    /**
     * @param CacheItemPoolInterface           $tokens               dedicated cache pool storing the token → user-id mapping
     * @param EntityManagerInterface           $entityManager        Doctrine entity manager used to flush the status transition
     * @param UserRepository                   $userRepository       read-side lookup of the user the token belongs to
     * @param AdminRegistrationNotifier        $adminNotifier        fires the site-wide admin-recipient notification once the email is confirmed
     * @param DomainRegistrationNotifier       $domainNotifier       fires the same notification to every approver (manager or admin) on the user's own domain
     * @param RegistrationConfirmationNotifier $confirmationNotifier fires the user-facing welcome mail once the email is confirmed
     * @param LoggerInterface                  $logger               receives a warning on transient mailer failures for any follow-up
     */
    public function __construct(
        #[Autowire(service: 'cache.email_confirmation')]
        private readonly CacheItemPoolInterface $tokens,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly AdminRegistrationNotifier $adminNotifier,
        private readonly DomainRegistrationNotifier $domainNotifier,
        private readonly RegistrationConfirmationNotifier $confirmationNotifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Mint a fresh confirmation token for `$user` and persist the
     * token → id mapping in the cache pool with a 24-hour TTL.
     *
     * Each call returns a new token; previous tokens for the same
     * user remain valid until their own TTL expires (issuing a
     * second token doesn't invalidate the first). This is by
     * design: a user who didn't receive the first email can ask
     * for another without invalidating the original, in case the
     * first lands in their spam folder later.
     *
     * @param User $user the user the token will authorise to confirm
     *
     * @return string the freshly-minted opaque token to embed in the email URL
     */
    public function issueToken(User $user): string
    {
        $token = $this->randomToken();

        $item = $this->tokens->getItem($this->cacheKey($token));
        $item->set((string) $user->getId());
        $item->expiresAfter(self::TOKEN_TTL_SECONDS);
        $this->tokens->save($item);

        return $token;
    }

    /**
     * Consume a confirmation token, transitioning the matched user
     * out of `AwaitingEmailConfirmation` and into `Pending`, and
     * dispatching the moderator + welcome follow-up mails.
     *
     * Returns the user on success so the caller can render a
     * personalised confirmation page. Returns `null` for every
     * failure mode — unknown token, expired token, missing user
     * row, or a user whose status has already moved on — so the
     * controller surfaces a uniform "this link is no longer valid"
     * response without leaking which specific case fired. Follow-
     * up mails are only sent when the transition actually
     * happens, so a second click on the same link never re-fires
     * them.
     *
     * Successful consumption deletes the cache row, so a second
     * click on the same link lands `null` (single-use).
     *
     * @param string $token the opaque token from the URL
     *
     * @return User|null the user whose status was transitioned, or null when the token is unusable
     */
    public function consume(string $token): ?User
    {
        $item = $this->tokens->getItem($this->cacheKey($token));
        if (!$item->isHit()) {
            return null;
        }

        $userId = (string) $item->get();
        $this->tokens->deleteItem($this->cacheKey($token));

        $user = $this->userRepository->find($userId);
        if (!$user instanceof User) {
            return null;
        }

        if (UserStatus::AwaitingEmailConfirmation !== $user->getStatus()) {
            // Idempotency: a user who clicks the same link twice
            // (after the cache row's already been deleted) would
            // also land here on a second `findOneBy` if any race
            // happens. Either way, surfacing null keeps the
            // success-page contract clear.
            return null;
        }

        $user->setStatus(UserStatus::Pending);
        $this->entityManager->flush();

        $this->dispatchFollowUpNotifications($user);

        return $user;
    }

    /**
     * Fire the two mails that follow a confirmed email address.
     *
     * Each send is wrapped in its own try/catch around the mailer
     * transport — a transient delivery failure must not undo the
     * status transition or 500 the confirmation success page, so
     * failures are logged and swallowed. Mirrors the try/catch
     * shape {@see Registration::dispatchNotifications()} uses on
     * the signup path.
     *
     * @param User $user the user whose status just flipped to `Pending`
     */
    private function dispatchFollowUpNotifications(User $user): void
    {
        try {
            $this->adminNotifier->notifyOfNewRegistration($user);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Failed to deliver admin registration notification.', [
                'user_email' => $user->getUserIdentifier(),
                'exception' => $e,
            ]);
        }

        // The domain notifier isolates each per-recipient transport
        // failure internally, so any exception escaping here is a hard
        // configuration problem (unset sender, undetermined domain) that
        // has already been logged inside the notifier — swallowing keeps
        // the status transition unaffected.
        try {
            $this->domainNotifier->notifyOfNewRegistration($user);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Failed to deliver domain registration notification.', [
                'user_email' => $user->getUserIdentifier(),
                'exception' => $e,
            ]);
        }

        try {
            $this->confirmationNotifier->confirmRegistration($user);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Failed to deliver registration confirmation mail.', [
                'user_email' => $user->getUserIdentifier(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Produce a URL-safe random token long enough to resist
     * online guessing across the 24-hour window the cache TTL
     * holds it. 32 random bytes → 43 base-64 chars after the `=`
     * padding is stripped.
     *
     * @return string the URL-safe random token
     */
    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Compute the cache key for a token. The prefix avoids
     * collisions with future consumers of the same pool, even
     * though today there's only one.
     *
     * @param string $token the opaque token
     *
     * @return string the namespaced cache key
     */
    private function cacheKey(string $token): string
    {
        return 'email_confirmation.'.$token;
    }
}
