<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\UserObfuscatePasswordsCommand;
use App\Repository\UserRepository;
use App\Security\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Unit-level coverage of the defensive branches on
 * {@see UserObfuscatePasswordsCommand} — the prod-environment
 * guard and the empty-user-list short-circuit. Both are
 * awkward to hit against the fixture-loaded integration
 * baseline, so we drive the command against mocked
 * collaborators.
 */
final class UserObfuscatePasswordsCommandTest extends TestCase
{
    // Ensures the command refuses to run when the kernel environment is `prod` and `--force` is not passed.
    public function testRefusesToRunInProdWithoutForce(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        // The prod guard fires before findAll is reached.
        $userRepository->expects(self::never())->method('findAll');

        $tester = $this->tester($userRepository, environment: 'prod');
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Refusing to run in the prod environment', $tester->getDisplay());
    }

    // Verifies the prod guard is bypassed when --force is passed.
    public function testProdGuardBypassedWithForce(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findAll')->willReturn([]);

        $tester = $this->tester($userRepository, environment: 'prod');
        $exit = $tester->execute(['--force' => true]);

        // Bypassed the guard → landed in the empty-users branch.
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No users to rewrite', $tester->getDisplay());
    }

    // Ensures an empty user list exits cleanly with the "no users" success message.
    public function testEmptyUserListExitsSuccess(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findAll')->willReturn([]);

        $tester = $this->tester($userRepository);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No users to rewrite', $tester->getDisplay());
    }

    /**
     * Build a `CommandTester` around a freshly-constructed
     * command. `UserManager` is `final`, so instead of mocking
     * it we build a real one against mocked collaborators — the
     * command doesn't call it in the branches we exercise here
     * (both return early before `changePassword()` is reached).
     */
    private function tester(
        UserRepository $userRepository,
        string $environment = 'test',
    ): CommandTester {
        $userManager = new UserManager(
            $this->createMock(EntityManagerInterface::class),
            $userRepository,
            $this->createMock(UserPasswordHasherInterface::class),
        );
        $command = new UserObfuscatePasswordsCommand($userManager, $userRepository, $environment);

        return new CommandTester($command);
    }
}
