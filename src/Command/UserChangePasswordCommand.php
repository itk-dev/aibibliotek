<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command that updates an existing user's password.
 *
 * Thin adapter over {@see UserManager::changePassword()}.
 */
#[AsCommand(
    name: 'app:user:change-password',
    description: 'Set a new hashed password for an existing user.',
)]
final class UserChangePasswordCommand extends Command
{
    /**
     * @param UserManager $userManager service that owns password rotation
     */
    public function __construct(private readonly UserManager $userManager)
    {
        parent::__construct();
    }

    /**
     * Declare CLI arguments.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The e-mail of the user whose password to change.')
            ->addArgument('password', InputArgument::REQUIRED, 'The new password in clear-text — will be hashed.');
    }

    /**
     * Adapt console arguments to the {@see UserManager} call.
     *
     * @param InputInterface  $input  CLI arguments
     * @param OutputInterface $output console output stream
     *
     * @return int Command::SUCCESS on a successful change, Command::FAILURE otherwise
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');

        try {
            $user = $this->userManager->changePassword($email, $password);
        } catch (\DomainException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Updated password for user "%s".', $user->getUserIdentifier()));

        return Command::SUCCESS;
    }
}
