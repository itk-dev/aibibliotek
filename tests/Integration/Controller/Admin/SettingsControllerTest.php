<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\DataFixtures\UserFixtures;
use App\Repository\UserRepository;
use App\Settings\SettingsManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end coverage of the admin settings split: `/admin/settings`
 * is a redirect entry point, `/admin/settings/site` houses the brand
 * identity fields, and `/admin/settings/email` houses the admin
 * recipient. Each form posts back to its own endpoint with its own
 * CSRF intent.
 *
 * Uses the baseline fixture users (`UserFixtures::ADMIN_EMAIL`,
 * `UserFixtures::DOMAIN_MANAGER_EMAIL`) loaded by
 * `tests/bootstrap_integration.php`; per-test mutations are rolled
 * back by `dama/doctrine-test-bundle`.
 */
final class SettingsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // Tests that an anonymous request to the admin settings entry point returns 401.
    public function testAnonymousAccessReturnsUnauthorized(): void
    {
        $this->client->request('GET', '/admin/settings');

        self::assertResponseStatusCodeSame(401);
    }

    // Tests that a plain authenticated user is 403'd — the route is ROLE_ADMIN.
    public function testPlainUserGets403(): void
    {
        $this->loginAsApproved('alice@example.test');

        $this->client->request('GET', '/admin/settings');

        self::assertResponseStatusCodeSame(403);
    }

    // Tests that a domain manager (no ROLE_ADMIN) is 403'd.
    public function testDomainManagerGets403(): void
    {
        $this->loginAsApproved(UserFixtures::DOMAIN_MANAGER_EMAIL);

        $this->client->request('GET', '/admin/settings');

        self::assertResponseStatusCodeSame(403);
    }

    // Verifies the entry point at /admin/settings redirects an admin to the site sub-page.
    public function testAdminSettingsRedirectsToSitePage(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/admin/settings');

        self::assertResponseRedirects('/admin/settings/site');
    }

    // Verifies the site form is pre-filled with the currently-stored brand identity values.
    public function testAdminSeesSiteFormPrefilledWithCurrentValues(): void
    {
        $this->loginAsAdmin();
        $settings = self::getContainer()->get(SettingsManager::class);
        $settings->setBrandName('Custom Name');
        $settings->setBrandTagline('Custom tagline');
        $settings->setBrandInitials('CN');

        $crawler = $this->client->request('GET', '/admin/settings/site');

        self::assertResponseIsSuccessful();
        self::assertSame('Custom Name', $crawler->filter('input[name="brand_name"]')->attr('value'));
        self::assertSame('Custom tagline', $crawler->filter('input[name="brand_tagline"]')->attr('value'));
        self::assertSame('CN', $crawler->filter('input[name="brand_initials"]')->attr('value'));
    }

    // Verifies the settings tabs render on the site page with the site tab marked active.
    public function testSitePageRendersTabsWithSiteActive(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/settings/site');

        self::assertResponseIsSuccessful();
        $tabs = $crawler->filter('nav[aria-label="Indstillinger – navigation"] a');
        self::assertCount(2, $tabs);
        $current = $tabs->filter('[aria-current="page"]');
        self::assertCount(1, $current);
        self::assertSame('/admin/settings/site', $current->attr('href'));
    }

    // Verifies the settings tabs render on the email page with the email tab marked active.
    public function testEmailPageRendersTabsWithEmailActive(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/settings/email');

        self::assertResponseIsSuccessful();
        $tabs = $crawler->filter('nav[aria-label="Indstillinger – navigation"] a');
        self::assertCount(2, $tabs);
        $current = $tabs->filter('[aria-current="page"]');
        self::assertCount(1, $current);
        self::assertSame('/admin/settings/email', $current->attr('href'));
    }

    // Tests that submitting valid site settings persists every brand field and redirects back to the form.
    public function testValidSiteSubmitPersistsBrandFields(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/settings/site');
        $form = $crawler->filter('form[action$="/admin/settings/site"]')->form([
            'brand_name' => 'New Brand',
            'brand_tagline' => 'New tagline',
            'brand_initials' => 'NB',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/settings/site');
        $settings = self::getContainer()->get(SettingsManager::class);
        self::assertSame('New Brand', $settings->getBrandName());
        self::assertSame('New tagline', $settings->getBrandTagline());
        self::assertSame('NB', $settings->getBrandInitials());
    }

    // Verifies the site form accepts a hero_text submission and the frontpage globals pick it up via SettingsManager::getHeroText().
    public function testValidSiteSubmitPersistsHeroText(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/settings/site');
        $form = $crawler->filter('form[action$="/admin/settings/site"]')->form([
            'hero_text' => 'Custom hero copy for the frontpage.',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/settings/site');
        self::assertSame(
            'Custom hero copy for the frontpage.',
            self::getContainer()->get(SettingsManager::class)->getHeroText(),
        );
    }

    // Verifies submitting empty brand values clears the stored override so the env-var fallback wins again.
    public function testEmptySiteSubmitRevertsToEnvDefaults(): void
    {
        $this->loginAsAdmin();
        $settings = self::getContainer()->get(SettingsManager::class);
        $settings->setBrandName('Custom Name');

        $crawler = $this->client->request('GET', '/admin/settings/site');
        $form = $crawler->filter('form[action$="/admin/settings/site"]')->form([
            'brand_name' => '',
            'brand_tagline' => '',
            'brand_initials' => '',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/settings/site');
        // With no stored override, the manager falls back to the env-var
        // default — the value injected via the test container.
        self::assertNotSame('Custom Name', self::getContainer()->get(SettingsManager::class)->getBrandName());
    }

    // Verifies that an invalid CSRF token on the site form returns 403 and never reaches SettingsManager.
    public function testInvalidSiteCsrfTokenIsRejected(): void
    {
        $this->loginAsAdmin();
        self::getContainer()->get(SettingsManager::class)->setBrandName('Untouched');

        $this->client->request('POST', '/admin/settings/site', [
            'brand_name' => 'Hijacked',
            'brand_tagline' => 'x',
            'brand_initials' => 'XX',
            '_token' => 'nope',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Untouched', self::getContainer()->get(SettingsManager::class)->getBrandName());
    }

    // Verifies the email form is pre-filled with the currently-stored admin recipient value.
    public function testAdminSeesEmailFormPrefilledWithCurrentValue(): void
    {
        $this->loginAsAdmin();
        self::getContainer()->get(SettingsManager::class)->setAdminRecipient('ops@example.test');

        $crawler = $this->client->request('GET', '/admin/settings/email');

        self::assertResponseIsSuccessful();
        $value = $crawler->filter('input[name="admin_recipient"]')->attr('value');
        self::assertSame('ops@example.test', $value);
    }

    // Verifies the email form pre-fills the subject + body templates for all three transactional emails from SettingsManager defaults so admins see what they'll be editing.
    public function testEmailFormPrefillsContentDefaults(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/settings/email');

        self::assertResponseIsSuccessful();
        self::assertNotEmpty(
            $crawler->filter('input[name="admin_notification_subject"]')->attr('value'),
            'admin notification subject must be pre-filled with the default',
        );
        self::assertNotEmpty(
            $crawler->filter('textarea[name="admin_notification_body"]')->text(),
            'admin notification body must be pre-filled with the default',
        );
        self::assertNotEmpty(
            $crawler->filter('input[name="registration_confirmation_subject"]')->attr('value'),
        );
        self::assertNotEmpty(
            $crawler->filter('textarea[name="registration_confirmation_body"]')->text(),
        );
        self::assertNotEmpty(
            $crawler->filter('input[name="email_confirmation_subject"]')->attr('value'),
            'email-confirmation subject must be pre-filled with the default',
        );
        self::assertNotEmpty(
            $crawler->filter('textarea[name="email_confirmation_body"]')->text(),
            'email-confirmation body must be pre-filled with the default',
        );
    }

    // Tests that submitting custom subject + body values persists every email-content field — including the confirmation-link templates — through SettingsManager.
    public function testValidEmailSubmitPersistsEmailContentFields(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/settings/email');
        $form = $crawler->filter('form[action$="/admin/settings/email"]')->form([
            'admin_recipient' => 'ops@example.test',
            'admin_notification_subject' => 'Custom admin subject',
            'admin_notification_body' => 'Custom admin body for %name%',
            'registration_confirmation_subject' => 'Custom user subject',
            'registration_confirmation_body' => 'Custom user body for %name%',
            'email_confirmation_subject' => 'Custom confirmation subject',
            'email_confirmation_body' => 'Bekræft %name%: %confirmation_url%',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/settings/email');
        $settings = self::getContainer()->get(SettingsManager::class);
        self::assertSame('Custom admin subject', $settings->getAdminNotificationSubject());
        self::assertSame('Custom admin body for %name%', $settings->getAdminNotificationBody());
        self::assertSame('Custom user subject', $settings->getRegistrationConfirmationSubject());
        self::assertSame('Custom user body for %name%', $settings->getRegistrationConfirmationBody());
        self::assertSame('Custom confirmation subject', $settings->getEmailConfirmationSubject());
        self::assertSame('Bekræft %name%: %confirmation_url%', $settings->getEmailConfirmationBody());
    }

    // Tests that submitting a valid email persists the value and redirects back to the form.
    public function testValidEmailSubmitPersistsRecipient(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/settings/email');
        $form = $crawler->filter('form[action$="/admin/settings/email"]')->form([
            'admin_recipient' => 'new@example.test',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/settings/email');
        self::assertSame(
            'new@example.test',
            self::getContainer()->get(SettingsManager::class)->getAdminRecipient(),
        );
    }

    // Verifies submitting an empty value clears the configured recipient (the setting goes null).
    public function testEmptyEmailSubmitClearsRecipient(): void
    {
        $this->loginAsAdmin();
        self::getContainer()->get(SettingsManager::class)->setAdminRecipient('ops@example.test');

        $crawler = $this->client->request('GET', '/admin/settings/email');
        $form = $crawler->filter('form[action$="/admin/settings/email"]')->form([
            'admin_recipient' => '',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/settings/email');
        self::assertNull(self::getContainer()->get(SettingsManager::class)->getAdminRecipient());
    }

    // Ensures an invalid email re-renders the form with a 422 status and leaves the stored value unchanged.
    public function testInvalidEmailRerendersFormWithoutPersisting(): void
    {
        $this->loginAsAdmin();
        self::getContainer()->get(SettingsManager::class)->setAdminRecipient('ops@example.test');

        $crawler = $this->client->request('GET', '/admin/settings/email');
        $form = $crawler->filter('form[action$="/admin/settings/email"]')->form([
            'admin_recipient' => 'not-an-email',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            'ops@example.test',
            self::getContainer()->get(SettingsManager::class)->getAdminRecipient(),
            'invalid submit must not overwrite the stored value',
        );
    }

    // Ensures the email settings form no longer renders a sender-address input — MAILER_FROM is deploy-time only.
    public function testEmailFormOmitsSenderAddressField(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/settings/email');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="sender_address"]'), 'sender_address must no longer be editable in the admin UI');
    }

    // Verifies that an invalid CSRF token on the email form returns 403 and never reaches SettingsManager.
    public function testInvalidEmailCsrfTokenIsRejected(): void
    {
        $this->loginAsAdmin();
        self::getContainer()->get(SettingsManager::class)->setAdminRecipient('ops@example.test');

        $this->client->request('POST', '/admin/settings/email', [
            'admin_recipient' => 'evil@example.test',
            '_token' => 'nope',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            'ops@example.test',
            self::getContainer()->get(SettingsManager::class)->getAdminRecipient(),
            'CSRF rejection must not overwrite the stored value',
        );
    }

    // Verifies GET /admin/settings/email renders both the cheat-sheet link and a Preview button per body field.
    public function testEmailFormRendersCheatSheetAndPreviewLinks(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/settings/email');

        self::assertResponseIsSuccessful();
        // One cheat-sheet link per body field; four fieldsets on the page
        // (admin notification, registration confirmation, email confirmation,
        // password reset).
        $cheatSheetLinks = $crawler->filter('a[href="https://www.markdownguide.org/cheat-sheet/"]');
        self::assertCount(4, $cheatSheetLinks);
        self::assertSame('_blank', $cheatSheetLinks->first()->attr('target'));
        self::assertSame('noopener noreferrer', $cheatSheetLinks->first()->attr('rel'));

        // One Preview button per body field.
        $previewButtons = $crawler->filter('button[data-action*="email-preview#open"]');
        self::assertCount(4, $previewButtons);
    }

    // Verifies POST /admin/settings/email/preview returns the substituted subject + rendered HTML for the acting admin.
    public function testPreviewEndpointReturnsRenderedHtml(): void
    {
        $this->loginAsAdmin();
        $token = $this->grabPreviewCsrfToken();

        $this->client->request(
            'POST',
            '/admin/settings/email/preview',
            content: json_encode([
                'subject' => 'Velkommen %name%',
                'body' => "Hej **%name%**!\n\nDin e-mail: %email%",
                '_token' => $token,
            ], JSON_THROW_ON_ERROR),
            server: ['CONTENT_TYPE' => 'application/json'],
        );

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJsonResponse();
        self::assertSame('Velkommen Admin', $payload['subject']);
        self::assertStringContainsString('<strong>Admin</strong>', $payload['html']);
        self::assertStringContainsString(UserFixtures::ADMIN_EMAIL, $payload['html']);
    }

    // Ensures the preview endpoint substitutes the synthetic approval_url token so admins can preview link output.
    public function testPreviewEndpointSubstitutesApprovalUrl(): void
    {
        $this->loginAsAdmin();
        $token = $this->grabPreviewCsrfToken();

        $this->client->request(
            'POST',
            '/admin/settings/email/preview',
            content: json_encode([
                'subject' => 'ignored',
                'body' => 'Approve: %approval_url%',
                '_token' => $token,
            ], JSON_THROW_ON_ERROR),
            server: ['CONTENT_TYPE' => 'application/json'],
        );

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJsonResponse();
        self::assertStringContainsString('/admin/users', $payload['html']);
    }

    // Ensures the preview endpoint rejects with 403 when the CSRF token is missing / invalid.
    public function testPreviewEndpointRejectsMissingCsrfToken(): void
    {
        $this->loginAsAdmin();

        $this->client->request(
            'POST',
            '/admin/settings/email/preview',
            content: json_encode([
                'subject' => 'x',
                'body' => 'y',
                '_token' => 'not-a-real-token',
            ], JSON_THROW_ON_ERROR),
            server: ['CONTENT_TYPE' => 'application/json'],
        );

        self::assertResponseStatusCodeSame(403);
    }

    // Ensures a plain (non-admin) user cannot hit the preview endpoint — the class-level IsGranted gate fires first.
    public function testPreviewEndpointRejectsNonAdmin(): void
    {
        $this->loginAsApproved('alice@example.test');

        $this->client->request(
            'POST',
            '/admin/settings/email/preview',
            content: json_encode([
                'subject' => 'x',
                'body' => 'y',
                '_token' => 'irrelevant',
            ], JSON_THROW_ON_ERROR),
            server: ['CONTENT_TYPE' => 'application/json'],
        );

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Fetch a valid `admin-settings-email-preview` CSRF token by
     * scraping the hidden carrier `<input>` rendered on the email
     * settings page. Loading the page also establishes the session
     * the token manager needs.
     */
    private function grabPreviewCsrfToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/settings/email');

        return (string) $crawler
            ->filter('div[data-email-preview-target="csrfForm"] input[name="_token"]')
            ->first()
            ->attr('value');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = json_decode($content, true);
        \assert(\is_array($decoded));

        return $decoded;
    }

    private function loginAsAdmin(): void
    {
        $this->loginAsApproved(UserFixtures::ADMIN_EMAIL);
    }

    private function loginAsApproved(string $email): void
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        \assert(null !== $user, 'Test user must be created before login.');
        $this->client->loginUser($user);
    }
}
