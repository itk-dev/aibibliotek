<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Relies on the baseline `UserFixtures` (alice + bob) loaded by
 * `tests/bootstrap_integration.php`. The "create fresh user" path uses
 * a non-fixture email to avoid colliding with the baseline.
 */
final class UserCreateCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();

        $application = new Application(self::$kernel);
        $command = $application->find('app:user:create');
        $this->tester = new CommandTester($command);
    }

    // Tests that the command creates a new user and reports success on a fresh email.
    public function testCreatesUser(): void
    {
        $exit = $this->tester->execute([
            'email' => 'charlie@example.test',
            'name' => 'Charlie',
            'password' => 'secret',
        ]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Created user "charlie@example.test"', $this->tester->getDisplay());
    }

    // Ensures the command exits non-zero when the email collides with an existing user.
    public function testReportsFailureWhenEmailAlreadyExists(): void
    {
        // alice@example.test is in the baseline fixtures.
        $exit = $this->tester->execute([
            'email' => 'alice@example.test',
            'name' => 'Alice',
            'password' => 'second',
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('already exists', $this->tester->getDisplay());
    }
}
