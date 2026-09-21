<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\UserStatus;
use App\Security\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command that creates a new application user.
 *
 * Thin adapter over {@see UserManager::createUser()}.
 *
 * The password is never a CLI argument. Anything passed on the command
 * line survives in shell history, is readable in the process list for
 * as long as the command runs, and is echoed into deployment logs, so
 * the secret is prompted for instead and hidden while typed. E-mail and
 * name may be passed as arguments — they are not secret — and are
 * prompted for when omitted.
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Create a new application user, prompting for the password.',
)]
final class UserCreateCommand extends Command
{
    /**
     * The clear-text password collected from the interactive prompt.
     *
     * Held between {@see self::interact()} and {@see self::execute()}
     * because it deliberately has no place in the input definition.
     * Null means no prompt ran, which is the `--no-interaction` case.
     */
    private ?string $password = null;

    /**
     * @param UserManager $userManager service that owns user creation
     */
    public function __construct(private readonly UserManager $userManager)
    {
        parent::__construct();
    }

    /**
     * Declare CLI arguments.
     *
     * Both arguments are optional so the command can be run bare and
     * answer for everything it needs.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'The user\'s e-mail address (must be unique). Prompted for when omitted.')
            ->addArgument('name', InputArgument::OPTIONAL, 'The user\'s display name. Prompted for when omitted.');
    }

    /**
     * Collect whatever the invocation did not supply.
     *
     * Console skips this entirely under `--no-interaction`, which is
     * why {@see self::execute()} re-checks rather than trusting that
     * the values are present.
     *
     * @param InputInterface  $input  CLI arguments, completed in place
     * @param OutputInterface $output console output stream
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);

        if (null === $input->getArgument('email')) {
            $input->setArgument('email', $io->ask('E-mail address'));
        }

        if (null === $input->getArgument('name')) {
            $input->setArgument('name', $io->ask('Display name'));
        }

        $this->password = $io->askHidden('Password (input hidden)');
    }

    /**
     * Adapt console arguments to the {@see UserManager} call.
     *
     * @param InputInterface  $input  CLI arguments
     * @param OutputInterface $output console output stream
     *
     * @return int Command::SUCCESS on creation, Command::FAILURE on a missing
     *             prompt, domain or validation error
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $name = (string) $input->getArgument('name');
        $password = (string) $this->password;

        if (in_array('', [$email, $name, $password], true)) {
            $io->error('E-mail, name and password are all required. The password is only ever collected interactively, so this command cannot run with --no-interaction.');

            return Command::FAILURE;
        }

        try {
            $user = $this->userManager->createUser($email, $name, $password, status: UserStatus::Approved);
        } catch (\DomainException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Created user "%s" (id=%s).', $user->getUserIdentifier(), $user->getId()));

        return Command::SUCCESS;
    }
}
