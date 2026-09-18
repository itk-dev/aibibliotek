<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end coverage of `app:user:obfuscate-passwords`.
 *
 * Uses the baseline user fixtures loaded by
 * `tests/bootstrap_integration.php`; DAMA rolls back every
 * password rewrite between tests so the fixtures survive.
 */
final class UserObfuscatePasswordsCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();

        $application = new Application(self::$kernel);
        $command = $application->find('app:user:obfuscate-passwords');
        $this->tester = new CommandTester($command);
    }

    // Verifies the default mode rewrites every user's password to a fresh random value that no longer matches "password" (the fixture default).
    public function testDefaultModeRewritesEveryPasswordToRandomValue(): void
    {
        $exit = $this->tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->tester->getDisplay();
        self::assertMatchesRegularExpression('/Rewrote passwords for \d+ user\(s\)\./', $display);

        $users = self::getContainer()->get(UserRepository::class)->findAll();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ($users as $user) {
            self::assertFalse(
                $hasher->isPasswordValid($user, 'password'),
                \sprintf('User %s must no longer accept the fixture password.', $user->getUserIdentifier()),
            );
        }
    }

    // Verifies --password sets every user to the same value, so the operator can log in as any of them in a staging environment.
    public function testSharedPasswordModeSetsSameValueEverywhere(): void
    {
        $exit = $this->tester->execute([
            '--password' => 'staging-shared-value',
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);

        $users = self::getContainer()->get(UserRepository::class)->findAll();
        self::assertNotEmpty($users, 'fixture baseline must seed users to rewrite');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ($users as $user) {
            self::assertTrue(
                $hasher->isPasswordValid($user, 'staging-shared-value'),
                \sprintf('User %s must accept the shared password.', $user->getUserIdentifier()),
            );
        }
    }

    // Ensures the confirmation prompt aborts cleanly on a "no" answer.
    public function testConfirmationPromptAbortsOnNo(): void
    {
        $this->tester->setInputs(['no']);
        $exit = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Aborted', $this->tester->getDisplay());
    }

    // Verifies the confirmation prompt accepts "yes" and proceeds.
    public function testConfirmationPromptProceedsOnYes(): void
    {
        $this->tester->setInputs(['yes']);
        $exit = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertMatchesRegularExpression('/Rewrote passwords for \d+ user\(s\)\./', $this->tester->getDisplay());
    }
}
