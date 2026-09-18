<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end coverage of {@see SettingsManager} against the real
 * `setting` table.
 *
 * `tests/bootstrap_integration.php` loads {@see \App\DataFixtures\SettingFixtures}
 * once at suite boot, so every key is pre-seeded with the fixture's
 * baseline value. `dama/doctrine-test-bundle` rolls per-test mutations
 * back to that seeded baseline.
 */
final class SettingsManagerTest extends KernelTestCase
{
    // Verifies getAdminRecipient returns null after the seeded row is explicitly cleared (the "intentionally unset" state).
    public function testGetAdminRecipientReturnsNullAfterClear(): void
    {
        $manager = self::getContainer()->get(SettingsManager::class);
        $manager->setAdminRecipient(null);

        self::assertNull($manager->getAdminRecipient());
    }

    // Tests that setAdminRecipient inserts a new row when none exists and the value round-trips through the repository.
    public function testSetAdminRecipientInsertsNewRow(): void
    {
        // SettingFixtures pre-seeded the row at suite boot. Delete it
        // first so this test still exercises the "row doesn't exist
        // yet → insert" branch of SettingsManager::setString().
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repository = self::getContainer()->get(SettingRepository::class);
        $existing = $repository->findOneByName(SettingsManager::ADMIN_RECIPIENT);
        \assert(null !== $existing, 'fixture must have seeded the row');
        $em->remove($existing);
        $em->flush();

        $manager = self::getContainer()->get(SettingsManager::class);

        $manager->setAdminRecipient('ops@example.test');

        self::assertSame('ops@example.test', $manager->getAdminRecipient());

        $row = $repository->findOneByName(SettingsManager::ADMIN_RECIPIENT);
        self::assertInstanceOf(Setting::class, $row);
        self::assertSame(SettingsManager::ADMIN_RECIPIENT, $row->getName());
        self::assertSame('ops@example.test', $row->getValue());
        self::assertNotNull($row->getId(), 'persisted row must carry an auto-generated id');
    }

    // Verifies the second call updates the existing row in place rather than inserting a duplicate.
    public function testSetAdminRecipientUpdatesExistingRow(): void
    {
        $manager = self::getContainer()->get(SettingsManager::class);

        $manager->setAdminRecipient('first@example.test');
        $manager->setAdminRecipient('second@example.test');

        self::assertSame('second@example.test', $manager->getAdminRecipient());

        $rowCount = self::getContainer()
            ->get(EntityManagerInterface::class)
            ->getRepository(Setting::class)
            ->count(['name' => SettingsManager::ADMIN_RECIPIENT]);
        self::assertSame(1, $rowCount, 'must reuse the row instead of inserting a duplicate');
    }

    // Ensures passing null clears the value (representing "intentionally unset" without deleting the row).
    public function testSetAdminRecipientNullClearsTheValue(): void
    {
        $manager = self::getContainer()->get(SettingsManager::class);

        $manager->setAdminRecipient('ops@example.test');
        $manager->setAdminRecipient(null);

        self::assertNull($manager->getAdminRecipient());
    }

    // Verifies applyAdminRecipient accepts a null payload as "clear the setting" without going through the trim path.
    public function testApplyAdminRecipientAcceptsNullAsAClear(): void
    {
        $manager = self::getContainer()->get(SettingsManager::class);
        $manager->setAdminRecipient('ops@example.test');

        self::assertTrue($manager->applyAdminRecipient(null));
        self::assertNull($manager->getAdminRecipient());
    }

    // Verifies applyAdminRecipient rejects garbage input without persisting it.
    public function testApplyAdminRecipientRejectsGarbage(): void
    {
        $manager = self::getContainer()->get(SettingsManager::class);
        $manager->setAdminRecipient('keepme@example.test');

        self::assertFalse($manager->applyAdminRecipient('not-an-email'));
        self::assertSame('keepme@example.test', $manager->getAdminRecipient(), 'invalid submit must not overwrite stored value');
    }

    // Verifies the sender accessor reads the MAILER_FROM env baked into the test container.
    public function testGetSenderAddressReadsMailerFromEnv(): void
    {
        $manager = self::getContainer()->get(SettingsManager::class);

        $sender = $manager->getSenderAddress();
        self::assertNotNull($sender, 'MAILER_FROM is set in .env.test so the accessor must resolve.');
        self::assertStringContainsString('@', $sender);
    }

    // Verifies getSenderAddress returns null when the MAILER_FROM env var is empty — the "no mailer configured" state notifiers use to short-circuit.
    public function testGetSenderAddressReturnsNullWhenEnvIsEmpty(): void
    {
        $container = self::getContainer();
        // Build a fresh manager whose env-var fallback is the empty
        // string — mirrors a deploy that left MAILER_FROM unset.
        $manager = new SettingsManager(
            $container->get(SettingRepository::class),
            $container->get(EntityManagerInterface::class),
            $container->get(\Symfony\Contracts\Translation\TranslatorInterface::class),
            'irrelevant',
            'irrelevant',
            'irrelevant',
            '',
        );

        self::assertNull($manager->getSenderAddress());
    }
}
