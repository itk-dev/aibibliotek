<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Uses baseline alice from `UserFixtures`, loaded by
 * `tests/bootstrap_integration.php`.
 */
final class UserChangePasswordCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();

        $application = new Application(self::$kernel);
        $command = $application->find('app:user:change-password');
        $this->tester = new CommandTester($command);
    }

    // Tests that the command updates the user's password and reports success.
    public function testChangesPassword(): void
    {
        $exit = $this->tester->execute([
            'email' => 'alice@example.test',
            'password' => 'new',
        ]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Updated password for user "alice@example.test"', $this->tester->getDisplay());
    }

    // Ensures the command exits non-zero and explains the failure when the email matches no user.
    public function testReportsFailureWhenUserMissing(): void
    {
        $exit = $this->tester->execute([
            'email' => 'nobody@example.test',
            'password' => 'new',
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('No user with the e-mail "nobody@example.test"', $this->tester->getDisplay());
    }
}
