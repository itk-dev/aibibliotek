<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Uses baseline alice from `UserFixtures`, loaded by
 * `tests/bootstrap_integration.php`.
 *
 * The password is not part of the input definition, so every case here
 * supplies it through the interactive prompt.
 */
final class UserChangePasswordCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $command = $application->find('app:user:change-password');
        $this->tester = new CommandTester($command);
    }

    // Tests that the command updates the user's password and reports success.
    public function testChangesPassword(): void
    {
        $this->tester->setInputs(['new']);

        $exit = $this->tester->execute(['email' => 'alice@example.test']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Updated password for user "alice@example.test"', $this->tester->getDisplay());
    }

    // Verifies the email is prompted for when the command is run with no arguments.
    public function testPromptsForEmailWhenRunBare(): void
    {
        $this->tester->setInputs(['alice@example.test', 'new']);

        $exit = $this->tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Updated password for user "alice@example.test"', $this->tester->getDisplay());
    }

    // Ensures the new password never appears in the output, so it cannot leak into a terminal recording or CI log.
    public function testPasswordIsNotEchoed(): void
    {
        $this->tester->setInputs(['sup3rsecret']);

        $this->tester->execute(['email' => 'alice@example.test']);

        self::assertStringNotContainsString('sup3rsecret', $this->tester->getDisplay());
    }

    // Ensures the command fails cleanly under --no-interaction, where the password cannot be collected.
    public function testFailsWithoutInteraction(): void
    {
        $exit = $this->tester->execute(
            ['email' => 'alice@example.test'],
            ['interactive' => false],
        );

        self::assertSame(1, $exit);
        self::assertStringContainsString('--no-interaction', $this->tester->getDisplay());
    }

    // Ensures the command exits non-zero and explains the failure when the email matches no user.
    public function testReportsFailureWhenUserMissing(): void
    {
        $this->tester->setInputs(['new']);

        $exit = $this->tester->execute(['email' => 'nobody@example.test']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('No user with the e-mail "nobody@example.test"', $this->tester->getDisplay());
    }

    // Verifies the repository feeding the email autocompletion returns the fixture addresses.
    public function testCollectEmailsBacksTheAutocompletion(): void
    {
        $emails = self::getContainer()->get(UserRepository::class)->collectEmails();

        self::assertContains('alice@example.test', $emails);
        self::assertSame(array_values(array_unique($emails)), $emails);
    }
}
