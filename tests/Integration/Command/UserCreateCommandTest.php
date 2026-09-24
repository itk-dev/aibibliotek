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
 *
 * The password is not part of the input definition, so every case here
 * supplies it through the interactive prompt.
 */
final class UserCreateCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $command = $application->find('app:user:create');
        $this->tester = new CommandTester($command);
    }

    // Tests that the command creates a new user and reports success on a fresh email.
    public function testCreatesUser(): void
    {
        $this->tester->setInputs(['secret']);

        $exit = $this->tester->execute([
            'email' => 'charlie@example.test',
            'name' => 'Charlie',
        ]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Created user "charlie@example.test"', $this->tester->getDisplay());
    }

    // Verifies email and name are prompted for when the command is run with no arguments at all.
    public function testPromptsForEveryValueWhenRunBare(): void
    {
        $this->tester->setInputs(['dora@example.test', 'Dora', 'secret']);

        $exit = $this->tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Created user "dora@example.test"', $this->tester->getDisplay());
    }

    // Ensures the password never appears in the output, so it cannot leak into a terminal recording or CI log.
    public function testPasswordIsNotEchoed(): void
    {
        $this->tester->setInputs(['erin@example.test', 'Erin', 'sup3rsecret']);

        $this->tester->execute([]);

        self::assertStringNotContainsString('sup3rsecret', $this->tester->getDisplay());
    }

    // Ensures the command fails cleanly under --no-interaction, where the password cannot be collected.
    public function testFailsWithoutInteraction(): void
    {
        $exit = $this->tester->execute(
            ['email' => 'frank@example.test', 'name' => 'Frank'],
            ['interactive' => false],
        );

        self::assertSame(1, $exit);
        self::assertStringContainsString('--no-interaction', $this->tester->getDisplay());
    }

    // Ensures the command exits non-zero when the email collides with an existing user.
    public function testReportsFailureWhenEmailAlreadyExists(): void
    {
        $this->tester->setInputs(['second']);

        // alice@example.test is in the baseline fixtures.
        $exit = $this->tester->execute([
            'email' => 'alice@example.test',
            'name' => 'Alice',
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('already exists', $this->tester->getDisplay());
    }
}
