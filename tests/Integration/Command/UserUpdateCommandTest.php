<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Relies on the baseline `UserFixtures` (alice + bob) loaded by
 * `tests/bootstrap_integration.php`.
 */
final class UserUpdateCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $application = new Application(self::$kernel);
        $command = $application->find('app:user:update');
        $this->tester = new CommandTester($command);
        $this->userRepository = $container->get(UserRepository::class);
    }

    // Tests that passing --name/--role/--status updates the corresponding fields.
    public function testUpdatesEveryProvidedField(): void
    {
        $exit = $this->tester->execute([
            'email' => 'alice@example.test',
            '--name' => 'Alice A.',
            '--role' => ['ROLE_ADMIN'],
            '--status' => 'blocked',
        ]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Updated user "alice@example.test"', $this->tester->getDisplay());

        $alice = $this->userRepository->findOneBy(['email' => 'alice@example.test']);
        self::assertNotNull($alice);
        self::assertSame('Alice A.', $alice->getName());
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $alice->getRoles());
        self::assertSame(UserStatus::Blocked, $alice->getStatus());
    }

    // Ensures the command is a no-op success when no options are passed.
    public function testNoOptionsIsANoOpSuccess(): void
    {
        $alice = $this->userRepository->findOneBy(['email' => 'alice@example.test']);
        self::assertNotNull($alice);
        $beforeName = $alice->getName();
        $beforeRoles = $alice->getRoles();
        $beforeStatus = $alice->getStatus();

        $exit = $this->tester->execute(['email' => 'alice@example.test']);

        self::assertSame(0, $exit);
        self::assertSame($beforeName, $alice->getName());
        self::assertSame($beforeRoles, $alice->getRoles());
        self::assertSame($beforeStatus, $alice->getStatus());
    }

    // Verifies that an unknown --status string is rejected with a non-zero exit and a hint of accepted values.
    public function testRejectsUnknownStatus(): void
    {
        $exit = $this->tester->execute([
            'email' => 'alice@example.test',
            '--status' => 'banana',
        ]);

        self::assertSame(1, $exit);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Unknown status "banana"', $display);
        self::assertStringContainsString('approved', $display);
    }

    // Ensures an unknown --role identifier is rejected by the service layer.
    public function testRejectsUnknownRole(): void
    {
        $exit = $this->tester->execute([
            'email' => 'alice@example.test',
            '--role' => ['ROLE_BOGUS'],
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('ROLE_BOGUS', $this->tester->getDisplay());
    }

    // Ensures the command exits non-zero with a clear message when the email matches no user.
    public function testReportsFailureWhenUserMissing(): void
    {
        $exit = $this->tester->execute([
            'email' => 'nobody@example.test',
            '--name' => 'Nobody',
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('No user with the e-mail "nobody@example.test"', $this->tester->getDisplay());
    }
}
