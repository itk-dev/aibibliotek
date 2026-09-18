<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\DataFixtures\UserFixtures;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Notification\AdminRegistrationNotifier;
use App\Notification\EmailConfirmationNotifier;
use App\Notification\RegistrationConfirmationNotifier;
use App\Repository\UserRepository;
use App\Settings\SettingsManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

/**
 * End-to-end coverage of the two registration notifiers.
 *
 * Symfony's `null://null` transport (configured in `.env.test`)
 * captures every sent message in the `MessageDataCollector` so
 * `MailerAssertionsTrait` can introspect them without
 * delivering anything off-box.
 */
final class NotifierIntegrationTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private SettingsManager $settings;
    private AdminRegistrationNotifier $adminNotifier;
    private RegistrationConfirmationNotifier $confirmationNotifier;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->settings = self::getContainer()->get(SettingsManager::class);
        $this->adminNotifier = self::getContainer()->get(AdminRegistrationNotifier::class);
        $this->confirmationNotifier = self::getContainer()->get(RegistrationConfirmationNotifier::class);
    }

    // Verifies the admin notifier sends to the configured recipient with the rendered subject and replaced %email% token.
    public function testAdminNotifierSendsToConfiguredRecipientWithRenderedSubject(): void
    {
        $this->settings->setAdminRecipient('ops@example.test');
        $this->settings->setAdminNotificationSubject('Ny bruger: %name%');
        $this->settings->setAdminNotificationBody('E-mail: %email%, godkend på %approval_url%.');

        $this->adminNotifier->notifyOfNewRegistration($this->makeUser());

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertSame('Ny bruger: Carol', $email->getSubject());
        self::assertSame('ops@example.test', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('carol@example.test', $email->getTextBody() ?? '');
    }

    // Ensures the admin notifier skips the send when the recipient is unset (the registration flow stays alive).
    public function testAdminNotifierSkipsWhenRecipientIsUnset(): void
    {
        // `SettingFixtures` pre-seeds an admin recipient at suite boot;
        // clear it explicitly here to exercise the "no recipient
        // configured" branch.
        $this->settings->setAdminRecipient(null);

        $this->adminNotifier->notifyOfNewRegistration($this->makeUser());

        self::assertEmailCount(0);
    }

    // Verifies the confirmation notifier sends to the registered user with the rendered template.
    public function testConfirmationNotifierSendsToRegisteredUser(): void
    {
        $this->settings->setRegistrationConfirmationSubject('Hej %name%');
        $this->settings->setRegistrationConfirmationBody('Tak for din oprettelse, %name%.');

        $this->confirmationNotifier->confirmRegistration($this->makeUser());

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertSame('Hej Carol', $email->getSubject());
        self::assertSame('carol@example.test', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('Tak for din oprettelse, Carol.', $email->getTextBody() ?? '');
    }

    // Verifies the email-confirmation notifier sends to the registered user, renders the admin-editable subject + body, and substitutes the %confirmation_url% token with the absolute link.
    public function testEmailConfirmationNotifierSendsConfirmationLink(): void
    {
        // Admin-editable templates: pinning the subject + body here
        // proves the notifier goes through SettingsManager and
        // EmailTemplateRenderer, not a hard-coded translation key.
        $this->settings->setEmailConfirmationSubject('Bekræft %name%');
        $this->settings->setEmailConfirmationBody('Klik %confirmation_url% for at bekræfte din e-mail %email%.');

        // UserFixtures seeds an AwaitingEmailConfirmation row at
        // awaiting@aalborg.dk — re-use it here so the notifier has
        // a persisted entity with an id for URL generation.
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => UserFixtures::AWAITING_EMAIL]);
        \assert(null !== $user, 'UserFixtures must seed the AwaitingEmailConfirmation baseline.');
        self::assertSame(UserStatus::AwaitingEmailConfirmation, $user->getStatus());

        $notifier = self::getContainer()->get(EmailConfirmationNotifier::class);
        $notifier->sendConfirmationLink($user);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertSame(UserFixtures::AWAITING_EMAIL, $email->getTo()[0]->getAddress());
        self::assertSame('Bekræft Awaiting', $email->getSubject());
        $text = (string) $email->getTextBody();
        self::assertStringContainsString('/auth/confirm-email/', $text, 'Plain-text body must include the substituted confirmation URL.');
        self::assertStringContainsString(UserFixtures::AWAITING_EMAIL, $text, '%email% token must be substituted into the body.');
    }

    private function makeUser(): User
    {
        return (new User())
            ->setEmail('carol@example.test')
            ->setName('Carol')
            ->setStatus(UserStatus::AwaitingEmailConfirmation);
    }
}
