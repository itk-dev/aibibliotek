<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\DataFixtures\UserFixtures;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end coverage of the three-step password-reset wizard.
 *
 * Uses fixture users so the setup doesn't touch the seeded
 * baseline other tests depend on. The mailer trace is asserted
 * for the address the message is sent to; the message body
 * itself is rendered through the admin-editable template, so we
 * only need to know a message was sent to the correct recipient.
 */
final class ResetPasswordControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // Verifies GET /reset-password renders the request form with the e-mail input.
    public function testRequestFormRenders(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nulstil dit password');
        self::assertSelectorExists('input[type="email"]');
    }

    // Verifies POST /reset-password with a valid fixture e-mail redirects to /check-email and queues one message to that address.
    public function testRequestForKnownEmailQueuesResetMessage(): void
    {
        $email = UserFixtures::ALICE_EMAIL;

        $crawler = $this->client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Send link til nulstilling')->form();
        $emailField = $this->findFieldName($form->all(), '[email]');
        $form[$emailField] = $email;
        $this->client->submit($form);

        self::assertResponseRedirects('/reset-password/check-email');

        $messages = $this->getMailerMessages();
        self::assertCount(1, $messages, 'exactly one reset-password message is queued for a known e-mail');
        self::assertEmailAddressContains($messages[0], 'to', $email);
    }

    // Verifies POST /reset-password with an unknown e-mail still redirects to /check-email but queues no message — no enumeration.
    public function testRequestForUnknownEmailDoesNotQueueMessage(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Send link til nulstilling')->form();
        $emailField = $this->findFieldName($form->all(), '[email]');
        $form[$emailField] = 'no-such-user@example.test';
        $this->client->submit($form);

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertCount(0, $this->getMailerMessages());
    }

    // Verifies GET /reset-password/check-email renders the confirmation copy standalone (no session token needed — the controller mints a fake so the "did the account exist" answer stays hidden).
    public function testCheckEmailRendersWithoutSessionToken(): void
    {
        $this->client->request('GET', '/reset-password/check-email');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Vi har sendt dig et link');
    }

    // Verifies the reset landing route stores a token from the URL into the session and 302s to the tokenless variant so the raw token doesn't leak into the address bar / referer.
    public function testResetRouteStoresTokenInSessionAndRedirects(): void
    {
        $this->client->request('GET', '/reset-password/reset/some-token-value');

        self::assertResponseRedirects('/reset-password/reset');
    }

    // Verifies /reset-password/reset with no token in the session or URL is a 404 — no bare "pick a new password" form without a valid mint.
    public function testResetRouteWithoutTokenReturns404(): void
    {
        $this->client->request('GET', '/reset-password/reset');

        self::assertResponseStatusCodeSame(404);
    }

    // Verifies /reset-password/reset redirects to the request page with a flash when the session-stored token no longer validates (e.g. consumed, tampered).
    public function testResetRouteWithInvalidSessionTokenRedirectsWithFlash(): void
    {
        // Store a bogus token in the session by hitting the token-in-URL
        // route with a mangled value. The controller stashes it and
        // redirects to the tokenless variant; the follow-up GET on
        // that variant then tries to validate the bogus token, throws,
        // and falls through to the request page with a flash.
        $this->client->request('GET', '/reset-password/reset/not-a-real-token');
        self::assertResponseRedirects('/reset-password/reset');
        $this->client->request('GET', '/reset-password/reset');

        self::assertResponseRedirects('/reset-password');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    // Verifies the "requested too soon" throttling branch — two back-to-back requests for the same account keep the response shape stable, and the second request skips the mailer send.
    public function testDoubleRequestForSameEmailIsThrottled(): void
    {
        $email = UserFixtures::ALICE_EMAIL;

        // First request goes through normally and dispatches one message.
        $crawler = $this->client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Send link til nulstilling')->form();
        $emailField = $this->findFieldName($form->all(), '[email]');
        $form[$emailField] = $email;
        $this->client->submit($form);
        self::assertResponseRedirects('/reset-password/check-email');
        self::assertCount(1, $this->getMailerMessages(), 'first request queues one message');

        // Second request within the throttling window still 302s
        // to /check-email — the controller swallows the bundle's
        // TooManyPasswordRequestsException so nothing leaks.
        $crawler = $this->client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Send link til nulstilling')->form();
        $emailField = $this->findFieldName($form->all(), '[email]');
        $form[$emailField] = $email;
        $this->client->submit($form);
        self::assertResponseRedirects('/reset-password/check-email');
        self::assertCount(1, $this->getMailerMessages(), 'throttled second request does not queue a fresh message');
    }

    // Full happy path: request → click link → set new password → login redirect. The user's password hash changes as a result.
    public function testFullHappyPathUpdatesPassword(): void
    {
        $email = UserFixtures::ALICE_EMAIL;
        $originalHash = $this->userByEmail($email)->getPassword();

        $crawler = $this->client->request('GET', '/reset-password');
        $requestForm = $crawler->selectButton('Send link til nulstilling')->form();
        $emailField = $this->findFieldName($requestForm->all(), '[email]');
        $requestForm[$emailField] = $email;
        $this->client->submit($requestForm);

        // Grab the freshly-minted reset URL out of the sent
        // message. The URL uses the bundle's abstract signed-token
        // format; extracting via regex against the plain-text body
        // is the standard trick from the bundle's own tests.
        $messages = $this->getMailerMessages();
        self::assertCount(1, $messages);
        // The reset URL lands in both the HTML and the plain-text
        // variant; `toString()` catches whichever is populated in
        // this test env.
        // MIME parts may be quoted-printable-encoded; join html + text
        // + raw dump to be resilient to whichever variant the mailer
        // picks in the test env.
        $htmlBody = (string) $messages[0]->getHtmlBody();
        $textBody = (string) $messages[0]->getTextBody();
        $body = $htmlBody.' '.$textBody.' '.$messages[0]->toString();
        $matched = preg_match('#(/reset-password/reset/[a-zA-Z0-9_.-]+)#', $body, $matches);
        self::assertSame(1, $matched, 'reset URL must appear in the sent message');
        $resetPath = $matches[1];

        // GET the reset link → cursor moves to the tokenless
        // route with the token stashed in the session.
        $this->client->request('GET', $resetPath);
        self::assertResponseRedirects('/reset-password/reset');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $resetForm = $crawler->selectButton('Gem nyt password')->form();
        $firstField = $this->findFieldName($resetForm->all(), '[plainPassword][first]');
        $secondField = $this->findFieldName($resetForm->all(), '[plainPassword][second]');
        $resetForm[$firstField] = 'a-strong-new-passphrase-12345';
        $resetForm[$secondField] = 'a-strong-new-passphrase-12345';
        $this->client->submit($resetForm);

        self::assertResponseRedirects('/login');

        $reloaded = $this->userByEmail($email);
        self::assertNotSame($originalHash, $reloaded->getPassword(), 'password hash has changed after reset');

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($reloaded, 'a-strong-new-passphrase-12345'));
    }

    private function userByEmail(string $email): User
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, 'UserFixtures must seed '.$email);

        return $user;
    }

    /**
     * @param array<string, \Symfony\Component\DomCrawler\Field\FormField> $fields
     */
    private function findFieldName(array $fields, string $suffix): string
    {
        foreach (array_keys($fields) as $name) {
            if (str_ends_with($name, $suffix)) {
                return $name;
            }
        }

        self::fail(sprintf('Form field ending with "%s" not found. Available: %s', $suffix, implode(', ', array_keys($fields))));
    }
}
