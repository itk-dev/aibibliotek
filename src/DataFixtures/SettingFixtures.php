<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Settings\SettingsManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Seed a baseline value for every key {@see SettingsManager}
 * currently exposes.
 *
 * A freshly-loaded local environment otherwise starts with an
 * empty `setting` table and every read falls through to the
 * env-var / translation fallback — which is fine for prod but
 * noisy for local demos and tests of features that read
 * settings. Writing through `SettingsManager` (rather than
 * instantiating `Setting` rows directly) keeps this fixture in
 * lock-step with the form path: adding a new setting means
 * adding one call here.
 *
 * No fixture dependency declared — settings stand on their own
 * and don't reference Users, Organizations, or Assistants.
 */
final class SettingFixtures extends Fixture implements FixtureGroupInterface
{
    /**
     * Belong to the `default` group so the Woodpecker stg pipeline can
     * load the general fixture set without also seeding
     * {@see LocalUserFixtures}' personal-inbox accounts (`--group=default`
     * then `--group=local --append`).
     *
     * @return list<string> group identifiers the fixtures bundle filters on
     */
    public static function getGroups(): array
    {
        return ['default'];
    }

    /**
     * @param SettingsManager $settingsManager service that owns the persistence + flush
     */
    public function __construct(private readonly SettingsManager $settingsManager)
    {
    }

    /**
     * Persist a baseline value for every admin-editable setting.
     *
     * The e-mail recipient uses the obviously-dev
     * `admin@example.test` so a fixture load can't be mistaken
     * for a real moderator inbox. Other values stay close to
     * the production defaults so the seeded environment looks
     * like what an operator would see after editing the form.
     *
     * @param ObjectManager $manager unused — SettingsManager flushes its own entity manager
     */
    public function load(ObjectManager $manager): void
    {
        $this->settingsManager->setAdminRecipient('admin@example.test');

        $this->settingsManager->setBrandName('AI Reolen');
        $this->settingsManager->setBrandTagline('Del & download · dansk offentlig AI');
        $this->settingsManager->setBrandInitials('AR');

        $this->settingsManager->setHeroText(
            'Find, del og download AI-assistenter bygget af danske myndigheder. Når en kommune løser en opgave, kan andre kommuner downloade assistenten og køre den lokalt — så gode løsninger skalerer nationalt.',
        );

        $this->settingsManager->setAdminNotificationSubject('Ny bruger venter godkendelse — %brand_name%');
        $this->settingsManager->setAdminNotificationBody(<<<'MD'
            En ny bruger har netop oprettet sig:

            - **Navn:** %name%
            - **E-mail:** %email%

            Brugeren afventer godkendelse. Gennemse køen på
            [%approval_url%](%approval_url%).
            MD);

        $this->settingsManager->setRegistrationConfirmationSubject('Velkommen til %brand_name%');
        $this->settingsManager->setRegistrationConfirmationBody(<<<'MD'
            Hej %name%,

            Tak for din oprettelse på %brand_name%. Vi har modtaget din
            forespørgsel og en administrator vil godkende kontoen,
            før du kan logge ind.

            Du modtager besked, så snart kontoen er klar.
            MD);
    }
}
