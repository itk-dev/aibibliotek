<?php

declare(strict_types=1);

namespace App\Settings;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Typed read/write surface for admin-editable runtime settings.
 *
 * Wraps the generic `setting` table so callers operate on real
 * values (e.g. an admin recipient `?string`) instead of poking at
 * `Setting` rows. Each setting key has a dedicated `get*`/`set*`
 * pair; add a new pair when adding a new setting rather than
 * exposing string keys to the call sites.
 *
 * Writes flush immediately — admin settings are low-volume and the
 * UX expectation is that a successful save takes effect on the
 * next request.
 */
class SettingsManager
{
    /**
     * Canonical key for the admin notification recipient address.
     *
     * Matched against {@see Setting::getName()}.
     */
    public const string ADMIN_RECIPIENT = 'admin_recipient';

    /**
     * Canonical key for the public-facing brand name (full title).
     */
    public const string BRAND_NAME = 'brand_name';

    /**
     * Canonical key for the public-facing brand tagline.
     */
    public const string BRAND_TAGLINE = 'brand_tagline';

    /**
     * Canonical key for the public-facing brand initials / logo glyph.
     */
    public const string BRAND_INITIALS = 'brand_initials';

    /**
     * Canonical key for the marketing-style "hero" copy rendered on
     * the public frontpage under the brand heading.
     *
     * Falls back to the `frontpage.hero.lead` translation when the
     * setting row is unset, so a fresh install renders the default
     * Danish copy until an operator overrides it.
     */
    public const string HERO_TEXT = 'hero_text';

    /**
     * Canonical keys for the admin-notification email content.
     */
    public const string ADMIN_NOTIFICATION_SUBJECT = 'admin_notification_subject';
    public const string ADMIN_NOTIFICATION_BODY = 'admin_notification_body';

    /**
     * Canonical keys for the registration-confirmation email content.
     */
    public const string REGISTRATION_CONFIRMATION_SUBJECT = 'registration_confirmation_subject';
    public const string REGISTRATION_CONFIRMATION_BODY = 'registration_confirmation_body';

    /**
     * Canonical keys for the email-confirmation link email content
     * (the third transactional message — the single-use link the
     * user clicks to leave `UserStatus::AwaitingEmailConfirmation`).
     */
    public const string EMAIL_CONFIRMATION_SUBJECT = 'email_confirmation_subject';
    public const string EMAIL_CONFIRMATION_BODY = 'email_confirmation_body';

    /**
     * Canonical keys for the password-reset link email content
     * (the fourth transactional message — the single-use link the
     * user clicks after asking to reset their password from the
     * `/reset-password` request page).
     */
    public const string PASSWORD_RESET_SUBJECT = 'password_reset_subject';
    public const string PASSWORD_RESET_BODY = 'password_reset_body';

    public function __construct(
        private readonly SettingRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        #[Autowire('%env(BRAND_NAME)%')]
        private readonly string $defaultBrandName,
        #[Autowire('%env(BRAND_TAGLINE)%')]
        private readonly string $defaultBrandTagline,
        #[Autowire('%env(BRAND_INITIALS)%')]
        private readonly string $defaultBrandInitials,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $defaultSenderAddress,
    ) {
    }

    /**
     * Read the configured admin notification recipient address.
     *
     * Returns the address an administrator typed into the
     * `/admin/settings` form, or `null` when no value has been
     * saved yet. Callers that need a guaranteed address should
     * fall back to a deploy-time `MAILER_FROM`/`MAILER_TO` env
     * convention.
     *
     * @return string|null configured admin recipient, or null when unset
     */
    public function getAdminRecipient(): ?string
    {
        return $this->getString(self::ADMIN_RECIPIENT);
    }

    /**
     * Persist the admin notification recipient address.
     *
     * Inserts a new `setting` row when the key is unset, otherwise
     * updates the existing one. Pass `null` to clear the setting
     * (the row stays but its value becomes `NULL`). Flushes
     * immediately so the next request sees the new value.
     *
     * @param string|null $email recipient address to store, or null to clear
     */
    public function setAdminRecipient(?string $email): void
    {
        $this->setString(self::ADMIN_RECIPIENT, $email);
    }

    /**
     * Read the transactional-mail sender (`From:`) address.
     *
     * Sourced from the deploy-time `MAILER_FROM` env var. Returns
     * `null` when the env var is empty / unset, signalling to
     * notifiers that they should skip the send — a fresh install
     * with no mailer configured is a legitimate operating state
     * (the moderator can still approve users through
     * `/admin/users`).
     *
     * The string may include a display-name component, e.g.
     * `"AI Reolen <noreply@…>"`; {@see \Symfony\Component\Mime\Address::create()}
     * parses both shapes on the send side.
     *
     * @return string|null the configured sender address, or null when MAILER_FROM is empty
     */
    public function getSenderAddress(): ?string
    {
        return '' === $this->defaultSenderAddress ? null : $this->defaultSenderAddress;
    }

    /**
     * Read the configured brand name.
     *
     * Returns the value an administrator typed into
     * `/admin/settings/site` when set, otherwise falls back to the
     * deploy-time `BRAND_NAME` env var. So a fresh install renders
     * the env-baked default until an admin overrides it through
     * the UI.
     *
     * @return string current brand name
     */
    public function getBrandName(): string
    {
        return $this->getString(self::BRAND_NAME) ?? $this->defaultBrandName;
    }

    /**
     * Persist the brand name.
     *
     * Inserts a new `setting` row when the key is unset, otherwise
     * updates the existing one. Pass `null` to revert to the
     * `BRAND_NAME` env-var default — the row stays but its value
     * becomes `NULL` so the fallback wins again.
     *
     * @param string|null $name brand name to store, or null to clear
     */
    public function setBrandName(?string $name): void
    {
        $this->setString(self::BRAND_NAME, $name);
    }

    /**
     * Read the configured brand tagline.
     *
     * Returns the value an administrator typed into
     * `/admin/settings/site` when set, otherwise falls back to the
     * deploy-time `BRAND_TAGLINE` env var.
     *
     * @return string current brand tagline
     */
    public function getBrandTagline(): string
    {
        return $this->getString(self::BRAND_TAGLINE) ?? $this->defaultBrandTagline;
    }

    /**
     * Persist the brand tagline.
     *
     * Inserts a new `setting` row when the key is unset, otherwise
     * updates the existing one. Pass `null` to revert to the
     * `BRAND_TAGLINE` env-var default.
     *
     * @param string|null $tagline brand tagline to store, or null to clear
     */
    public function setBrandTagline(?string $tagline): void
    {
        $this->setString(self::BRAND_TAGLINE, $tagline);
    }

    /**
     * Read the configured brand initials / logo glyph.
     *
     * Returns the value an administrator typed into
     * `/admin/settings/site` when set, otherwise falls back to the
     * deploy-time `BRAND_INITIALS` env var.
     *
     * @return string current brand initials
     */
    public function getBrandInitials(): string
    {
        return $this->getString(self::BRAND_INITIALS) ?? $this->defaultBrandInitials;
    }

    /**
     * Persist the brand initials.
     *
     * Inserts a new `setting` row when the key is unset, otherwise
     * updates the existing one. Pass `null` to revert to the
     * `BRAND_INITIALS` env-var default.
     *
     * @param string|null $initials brand initials to store, or null to clear
     */
    public function setBrandInitials(?string $initials): void
    {
        $this->setString(self::BRAND_INITIALS, $initials);
    }

    /**
     * Read the configured hero copy rendered on the public frontpage.
     *
     * Returns the admin-saved override when set, otherwise the
     * translated `frontpage.hero.lead` default so a fresh install
     * keeps rendering the same Danish copy until an operator
     * overrides it through `/admin/settings/site`.
     *
     * @return string current hero copy
     */
    public function getHeroText(): string
    {
        return $this->getString(self::HERO_TEXT)
            ?? $this->translator->trans('frontpage.hero.lead');
    }

    /**
     * Persist the frontpage hero copy.
     *
     * Inserts a new `setting` row when the key is unset, otherwise
     * updates the existing one. Pass `null` to revert to the
     * translation-based default.
     *
     * @param string|null $text hero copy to store, or null to clear
     */
    public function setHeroText(?string $text): void
    {
        $this->setString(self::HERO_TEXT, $text);
    }

    /**
     * Read the configured subject template for the admin notification email.
     *
     * Returns the admin-saved override when set, otherwise the
     * translated `settings.admin.notification_subject` default
     * sourced from the `messages` translation domain. The `%token%`
     * placeholders pass through `trans()` literally because the
     * call site supplies no parameter map; the downstream
     * {@see \App\Mail\EmailTemplateRenderer} does the substitution.
     *
     * @return string current subject template (may contain `%token%` placeholders)
     */
    public function getAdminNotificationSubject(): string
    {
        return $this->getString(self::ADMIN_NOTIFICATION_SUBJECT)
            ?? $this->translator->trans('settings.admin.notification_subject');
    }

    /**
     * Persist the admin notification subject template, or clear it to revert to the default.
     *
     * @param string|null $subject subject template to store, or null to clear
     */
    public function setAdminNotificationSubject(?string $subject): void
    {
        $this->setString(self::ADMIN_NOTIFICATION_SUBJECT, $subject);
    }

    /**
     * Read the configured Markdown body template for the admin notification email.
     *
     * Returns the admin-saved override when set, otherwise the
     * translated `settings.admin.notification_body` default. The
     * `%token%` placeholders pass through `trans()` literally
     * because no parameter map is supplied.
     *
     * @return string current Markdown body template (may contain `%token%` placeholders)
     */
    public function getAdminNotificationBody(): string
    {
        return $this->getString(self::ADMIN_NOTIFICATION_BODY)
            ?? $this->translator->trans('settings.admin.notification_body');
    }

    /**
     * Persist the admin notification body template, or clear it to revert to the default.
     *
     * @param string|null $body Markdown body template to store, or null to clear
     */
    public function setAdminNotificationBody(?string $body): void
    {
        $this->setString(self::ADMIN_NOTIFICATION_BODY, $body);
    }

    /**
     * Read the configured subject template for the signup-confirmation email.
     *
     * Falls back to the `settings.registration.confirmation_subject`
     * translation when the row is unset.
     *
     * @return string current subject template
     */
    public function getRegistrationConfirmationSubject(): string
    {
        return $this->getString(self::REGISTRATION_CONFIRMATION_SUBJECT)
            ?? $this->translator->trans('settings.registration.confirmation_subject');
    }

    /**
     * Persist the registration-confirmation subject template, or clear it to revert to the default.
     *
     * @param string|null $subject subject template to store, or null to clear
     */
    public function setRegistrationConfirmationSubject(?string $subject): void
    {
        $this->setString(self::REGISTRATION_CONFIRMATION_SUBJECT, $subject);
    }

    /**
     * Read the configured Markdown body template for the signup-confirmation email.
     *
     * Falls back to the `settings.registration.confirmation_body`
     * translation when the row is unset.
     *
     * @return string current Markdown body template
     */
    public function getRegistrationConfirmationBody(): string
    {
        return $this->getString(self::REGISTRATION_CONFIRMATION_BODY)
            ?? $this->translator->trans('settings.registration.confirmation_body');
    }

    /**
     * Persist the registration-confirmation body template, or clear it to revert to the default.
     *
     * @param string|null $body Markdown body template to store, or null to clear
     */
    public function setRegistrationConfirmationBody(?string $body): void
    {
        $this->setString(self::REGISTRATION_CONFIRMATION_BODY, $body);
    }

    /**
     * Read the configured subject template for the email-confirmation link email.
     *
     * Falls back to the `settings.email_confirmation.subject`
     * translation when the row is unset.
     *
     * @return string current subject template
     */
    public function getEmailConfirmationSubject(): string
    {
        return $this->getString(self::EMAIL_CONFIRMATION_SUBJECT)
            ?? $this->translator->trans('settings.email_confirmation.subject');
    }

    /**
     * Persist the email-confirmation subject template, or clear it to revert to the default.
     *
     * @param string|null $subject subject template to store, or null to clear
     */
    public function setEmailConfirmationSubject(?string $subject): void
    {
        $this->setString(self::EMAIL_CONFIRMATION_SUBJECT, $subject);
    }

    /**
     * Read the configured Markdown body template for the email-confirmation link email.
     *
     * Falls back to the `settings.email_confirmation.body`
     * translation when the row is unset.
     *
     * @return string current Markdown body template
     */
    public function getEmailConfirmationBody(): string
    {
        return $this->getString(self::EMAIL_CONFIRMATION_BODY)
            ?? $this->translator->trans('settings.email_confirmation.body');
    }

    /**
     * Persist the email-confirmation body template, or clear it to revert to the default.
     *
     * @param string|null $body Markdown body template to store, or null to clear
     */
    public function setEmailConfirmationBody(?string $body): void
    {
        $this->setString(self::EMAIL_CONFIRMATION_BODY, $body);
    }

    /**
     * Read the configured subject template for the password-reset link email.
     *
     * Falls back to the `settings.password_reset.subject`
     * translation when the row is unset.
     *
     * @return string current subject template
     */
    public function getPasswordResetSubject(): string
    {
        return $this->getString(self::PASSWORD_RESET_SUBJECT)
            ?? $this->translator->trans('settings.password_reset.subject');
    }

    /**
     * Persist the password-reset subject template, or clear it to revert to the default.
     *
     * @param string|null $subject subject template to store, or null to clear
     */
    public function setPasswordResetSubject(?string $subject): void
    {
        $this->setString(self::PASSWORD_RESET_SUBJECT, $subject);
    }

    /**
     * Read the configured Markdown body template for the password-reset link email.
     *
     * Falls back to the `settings.password_reset.body`
     * translation when the row is unset.
     *
     * @return string current Markdown body template
     */
    public function getPasswordResetBody(): string
    {
        return $this->getString(self::PASSWORD_RESET_BODY)
            ?? $this->translator->trans('settings.password_reset.body');
    }

    /**
     * Persist the password-reset body template, or clear it to revert to the default.
     *
     * @param string|null $body Markdown body template to store, or null to clear
     */
    public function setPasswordResetBody(?string $body): void
    {
        $this->setString(self::PASSWORD_RESET_BODY, $body);
    }

    /**
     * Apply the email-content submission (subjects + bodies for all four transactional emails) in one call.
     *
     * Each argument is trimmed and empty strings collapse to
     * `null`, so clearing a field reverts that template to the
     * built-in default.
     *
     * @param string|null $adminNotificationSubject        submitted subject for the admin notification email
     * @param string|null $adminNotificationBody           submitted Markdown body for the admin notification email
     * @param string|null $registrationConfirmationSubject submitted subject for the signup-confirmation email
     * @param string|null $registrationConfirmationBody    submitted Markdown body for the signup-confirmation email
     * @param string|null $emailConfirmationSubject        submitted subject for the confirmation-link email
     * @param string|null $emailConfirmationBody           submitted Markdown body for the confirmation-link email
     * @param string|null $passwordResetSubject            submitted subject for the password-reset link email
     * @param string|null $passwordResetBody               submitted Markdown body for the password-reset link email
     */
    public function applyEmailContent(
        ?string $adminNotificationSubject,
        ?string $adminNotificationBody,
        ?string $registrationConfirmationSubject,
        ?string $registrationConfirmationBody,
        ?string $emailConfirmationSubject = null,
        ?string $emailConfirmationBody = null,
        ?string $passwordResetSubject = null,
        ?string $passwordResetBody = null,
    ): void {
        $this->setAdminNotificationSubject(self::emptyToNull($adminNotificationSubject));
        $this->setAdminNotificationBody(self::emptyToNull($adminNotificationBody));
        $this->setRegistrationConfirmationSubject(self::emptyToNull($registrationConfirmationSubject));
        $this->setRegistrationConfirmationBody(self::emptyToNull($registrationConfirmationBody));
        $this->setEmailConfirmationSubject(self::emptyToNull($emailConfirmationSubject));
        $this->setEmailConfirmationBody(self::emptyToNull($emailConfirmationBody));
        $this->setPasswordResetSubject(self::emptyToNull($passwordResetSubject));
        $this->setPasswordResetBody(self::emptyToNull($passwordResetBody));
    }

    /**
     * Apply a raw site-identity submission in one call.
     *
     * Each argument is trimmed and empty strings are treated as
     * `null`, so a form that submits empty fields reverts the
     * override and lets the relevant default (env var for the
     * brand fields, translation key for `hero_text`) win again.
     * Every key is persisted on the same flush.
     *
     * @param string|null $name     submitted brand name
     * @param string|null $tagline  submitted brand tagline
     * @param string|null $initials submitted brand initials
     * @param string|null $heroText submitted frontpage hero copy
     */
    public function applyBrandIdentity(?string $name, ?string $tagline, ?string $initials, ?string $heroText): void
    {
        $this->setBrandName(self::emptyToNull($name));
        $this->setBrandTagline(self::emptyToNull($tagline));
        $this->setBrandInitials(self::emptyToNull($initials));
        $this->setHeroText(self::emptyToNull($heroText));
    }

    /**
     * Validate (and normalise) a submitted admin notification recipient.
     *
     * Trims the input. Returns the trimmed address when it parses
     * as a valid e-mail (or `null` when the input was empty, meaning
     * "clear the row"), or `false` when the input is non-empty but
     * not a syntactically valid e-mail. Pure — does not touch the
     * repository.
     *
     * Pair with {@see validateSenderAddress()} to validate an entire
     * email-settings submission before persisting any field.
     *
     * @param string|null $address raw submitted recipient address
     *
     * @return string|false|null trimmed address (or null to clear) on accept, false on invalid
     */
    public function validateAdminRecipient(?string $address): string|false|null
    {
        $normalised = self::emptyToNull($address);
        if (null !== $normalised && !filter_var($normalised, \FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return $normalised;
    }

    /**
     * Validate and persist an admin notification recipient submission in one call.
     *
     * Delegates validation to {@see validateAdminRecipient()} and
     * persists via {@see setAdminRecipient()} on accept. Prefer the
     * split form ({@see validateAdminRecipient()} +
     * {@see setAdminRecipient()}) when multiple fields share a
     * single form so the whole submission can be validated before
     * any row is written.
     *
     * @param string|null $address raw submitted recipient address
     *
     * @return bool true on accept (cleared or persisted), false on a syntactically invalid non-empty address
     */
    public function applyAdminRecipient(?string $address): bool
    {
        $result = $this->validateAdminRecipient($address);
        if (false === $result) {
            return false;
        }

        $this->setAdminRecipient($result);

        return true;
    }

    /**
     * Trim a string and return `null` when the result is empty.
     *
     * Centralises the "empty form field → unset the setting"
     * convention so the typed apply-* methods all behave the
     * same way. Internal helper — callers go through the typed
     * apply-* methods instead.
     *
     * @param string|null $value raw input from a form submission
     *
     * @return string|null trimmed value, or null when input is null or only whitespace
     */
    private static function emptyToNull(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * Look up a stored string setting by key.
     *
     * Returns the row's `value`, or `null` when the row doesn't
     * exist or its value is `NULL`. Internal helper — callers go
     * through the typed accessors instead.
     *
     * @param string $name canonical setting key
     *
     * @return string|null stored value, or null when unset
     */
    private function getString(string $name): ?string
    {
        return $this->repository->findOneByName($name)?->getValue();
    }

    /**
     * Persist (insert or update) a string setting.
     *
     * Looks up the row by name, creates one when missing, sets its
     * value, and flushes. Internal helper — callers go through the
     * typed accessors instead.
     *
     * @param string      $name  canonical setting key
     * @param string|null $value value to store, or null to clear
     */
    private function setString(string $name, ?string $value): void
    {
        $setting = $this->repository->findOneByName($name);
        if (null === $setting) {
            $setting = new Setting($name, $value);
            $this->em->persist($setting);
        } else {
            $setting->setValue($value);
        }
        $this->em->flush();
    }
}
