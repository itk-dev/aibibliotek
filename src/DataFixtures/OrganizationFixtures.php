<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Organization;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Seed three baseline organizations for local development and tests.
 *
 * Hand-picked Danish municipalities that match the municipality names already used
 * by AssistantFixtures, so downstream features (autocomplete, facet
 * counts) can be wired up against consistent data once the
 * `User → Organization` relation lands.
 */
final class OrganizationFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
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

    public function load(ObjectManager $manager): void
    {
        $entries = [
            new Organization(
                name: 'Aarhus Kommune',
                emailDomains: ['aarhus.dk'],
                defaultFramework: 'openwebui',
            ),
            new Organization(
                name: 'Aalborg Kommune',
                emailDomains: ['aalborg.dk', 'aalborgkommune.dk'],
                defaultFramework: 'openwebui',
            ),
            new Organization(
                name: 'Odense Kommune',
                emailDomains: ['odense.dk'],
                defaultFramework: 'openwebui',
            ),
            // Stand-in organisation that owns the `example.test`
            // domain shared by the `alice@example.test` /
            // `bob@example.test` fixture users and the integration
            // test suite. `AllowedEmailDomains` now sources its
            // allow-list from these rows, so the test domain has
            // to live here for self-signup with `@example.test`
            // to pass.
            new Organization(
                name: 'Eksempel Kommune',
                emailDomains: ['example.test'],
                defaultFramework: 'openwebui',
            ),
        ];

        // The two fixture users own the organizations round-robin; resolved
        // once and reused so they share the same managed instances.
        $creators = FixtureCreators::resolve($manager);

        $index = 0;
        foreach ($entries as $organization) {
            FixtureCreators::assign($creators, $organization, $index++);
            $manager->persist($organization);
        }
        $manager->flush();
    }

    /**
     * Declare that users must be loaded first.
     *
     * The creating users are looked up by e-mail in {@see FixtureCreators},
     * so {@see UserFixtures} has to run — and commit alice and bob — before
     * this fixture.
     *
     * @return array<class-string> the fixture classes this one depends on
     */
    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }
}
