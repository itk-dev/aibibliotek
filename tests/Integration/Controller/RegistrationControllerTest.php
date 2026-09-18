<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Security\Registration;
use App\Settings\SettingsManager;
use App\Tests\Support\ClosedLimiterFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end self-signup flow against the real `form_login` firewall
 * and the `AccountStatusChecker`.
 *
 * The test env's allow-list default is `example.test` (see
 * `config/services.yaml`), so `*.@example.test` emails are accepted
 * while everything else is rejected.
 */
final class RegistrationControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // Tests that the /register form renders the expected fields and CSRF token.
    public function testRegisterPageRenders(): void
    {
        $this->client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="email"]');
        self::assertSelectorExists('input[name="name"]');
        self::assertSelectorExists('input[name="password"]');
        self::assertSelectorExists('input[name="password_confirm"]');
        self::assertSelectorExists('input[name="_token"]');
    }

    // Verifies a valid submission creates an AwaitingEmailConfirmation user and redirects to the pending page.
    public function testSuccessfulRegistrationCreatesAwaitingUserAndRedirects(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['email'] = 'eve@example.test';
        $form['name'] = 'Eve';
        $form['password'] = 'secret';
        $form['password_confirm'] = 'secret';
        $this->client->submit($form);

        self::assertResponseRedirects('/register/pending');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('bekræftelsesmail', $crawler->filter('body')->text());

        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'eve@example.test']);
        self::assertNotNull($user);
        self::assertSame('Eve', $user->getName());
        self::assertSame(UserStatus::AwaitingEmailConfirmation, $user->getStatus());
    }

    // Ensures a successful self-signup fires exactly one email — the single-use confirmation link — and nothing hits the moderator or the welcome inbox until the address is verified.
    public function testSuccessfulRegistrationFiresConfirmationLinkOnly(): void
    {
        self::getContainer()->get(SettingsManager::class)->setAdminRecipient('ops@example.test');

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['email'] = 'grace@example.test';
        $form['name'] = 'Grace';
        $form['password'] = 'secret';
        $form['password_confirm'] = 'secret';
        $this->client->submit($form);

        self::assertResponseRedirects('/register/pending');
        self::assertEmailCount(1);

        $recipients = array_map(
            static fn (\Symfony\Component\Mime\RawMessage $message): string => method_exists($message, 'getTo')
                ? ($message->getTo()[0]?->getAddress() ?? '')
                : '',
            self::getMailerMessages(),
        );
        // The single mail goes to the signing-up user — the
        // moderator notification and the welcome mail move to
        // EmailConfirmation::consume() so they only fire once
        // the address is verified.
        self::assertSame(['grace@example.test'], $recipients);
    }

    // Verifies the hand-off through AccountStatusChecker: a freshly-registered user cannot log in until their email is confirmed and a moderator approves.
    public function testAwaitingEmailConfirmationUserCreatedByRegistrationCannotLogIn(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['email'] = 'frank@example.test';
        $form['name'] = 'Frank';
        $form['password'] = 'secret';
        $form['password_confirm'] = 'secret';
        $this->client->submit($form);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form')->form();
        $form['_username'] = 'frank@example.test';
        $form['_password'] = 'secret';
        $this->client->submit($form);

        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        // The status checker throws account.awaiting_email_confirmation
        // which surfaces the "bekræfte din e-mailadresse" message.
        self::assertStringContainsString('bekræfte din e-mailadresse', $crawler->filter('body')->text());
        self::assertNull(
            $this->client->getContainer()->get('security.token_storage')->getToken(),
        );
    }

    // Ensures emails outside the allow-list are rejected with 422 and no user persisted.
    public function testRejectsNonAllowListedDomain(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['email'] = 'mallory@other.invalid';
        $form['name'] = 'Mallory';
        $form['password'] = 'secret';
        $form['password_confirm'] = 'secret';
        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('domæne er ikke godkendt', $crawler->filter('body')->text());
        self::assertNull(
            self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'mallory@other.invalid']),
        );
    }

    // Ensures mismatched password + confirmation are rejected with 422.
    public function testRejectsPasswordMismatch(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['email'] = 'grace@example.test';
        $form['name'] = 'Grace';
        $form['password'] = 'secret';
        $form['password_confirm'] = 'different';
        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('ikke ens', $crawler->filter('body')->text());
    }

    // Verifies idempotency: re-registering with an existing email returns the same "thanks, awaiting approval" redirect — no information leak about whether the address is taken.
    public function testDuplicateEmailIsIdempotent(): void
    {
        // alice@example.test is in the baseline fixtures; submitting her
        // address must look identical to a fresh signup from the outside.
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['email'] = 'alice@example.test';
        $form['name'] = 'Alice';
        $form['password'] = 'secret';
        $form['password_confirm'] = 'secret';
        $this->client->submit($form);

        self::assertResponseRedirects('/register/pending');
    }

    // Ensures an invalid CSRF token yields 403 and no user is persisted.
    public function testRejectsInvalidCsrfToken(): void
    {
        $this->client->request('POST', '/register', [
            'email' => 'henry@example.test',
            'name' => 'Henry',
            'password' => 'secret',
            'password_confirm' => 'secret',
            '_token' => 'nope',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull(
            self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'henry@example.test']),
        );
    }

    // Tests that an authenticated visitor hitting /register is redirected to the frontpage.
    public function testLoggedInUserIsRedirectedAwayFromRegister(): void
    {
        $this->loginAsAlice();

        $this->client->request('GET', '/register');

        self::assertResponseRedirects('/');
    }

    // Tests that an authenticated visitor hitting /register/pending is redirected to the frontpage.
    public function testLoggedInUserIsRedirectedAwayFromPendingPage(): void
    {
        $this->loginAsAlice();

        $this->client->request('GET', '/register/pending');

        self::assertResponseRedirects('/');
    }

    // Verifies the controller maps RateLimitedRegistrationException to a 429 response with the localised hint, by swapping in a Registration backed by an always-rejecting limiter.
    public function testControllerReturns429WhenRegistrationRejectsForRateLimit(): void
    {
        // Swap the Registration service for one with a closed limiter
        // before any code touches it, then disable kernel reboot so the
        // swap survives the request lifecycle.
        $container = self::getContainer();
        $container->set(Registration::class, new Registration(
            $container->get(\App\Security\UserManager::class),
            $container->get(\App\Security\AllowedEmailDomains::class),
            $container->get(\App\Notification\EmailConfirmationNotifier::class),
            new \Psr\Log\NullLogger(),
            ClosedLimiterFactory::create(),
            ClosedLimiterFactory::create(),
        ));
        $this->client->disableReboot();

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['email'] = 'wave@example.test';
        $form['name'] = 'Wave';
        $form['password'] = 'secret';
        $form['password_confirm'] = 'secret';
        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('for mange anmodninger', $crawler->filter('body')->text());
    }

    private function loginAsAlice(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form')->form();
        $form['_username'] = 'alice@example.test';
        $form['_password'] = 'password';
        $this->client->submit($form);
        $this->client->followRedirect();
    }
}
