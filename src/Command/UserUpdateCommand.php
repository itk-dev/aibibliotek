<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\UserStatus;
use App\Security\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command that updates a user's display name, roles,
 * and / or lifecycle status.
 *
 * Thin adapter over {@see UserManager::updateUser()}. Only the
 * options the caller passes are applied; omitting an option
 * leaves the field untouched. Passing `--role` (one or more)
 * replaces the user's role list wholesale.
 */
#[AsCommand(
    name: 'app:user:update',
    description: "Update an existing user's name, roles, and/or status.",
)]
final class UserUpdateCommand extends Command
{
    /**
     * @param UserManager $userManager service that owns user mutation
     */
    public function __construct(private readonly UserManager $userManager)
    {
        parent::__construct();
    }

    /**
     * Declare CLI arguments and options.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The e-mail of the user to update.')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'New display name.')
            ->addOption(
                'role',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Role identifier to assign. Pass repeatedly to set multiple roles. Replaces the current role list.',
            )
            ->addOption(
                'status',
                null,
                InputOption::VALUE_REQUIRED,
                'Lifecycle status (one of: awaiting_email_confirmation, pending, approved, blocked).',
            );
    }

    /**
     * Adapt console arguments and options to the {@see UserManager} call.
     *
     * @param InputInterface  $input  CLI arguments
     * @param OutputInterface $output console output stream
     *
     * @return int Command::SUCCESS on a successful update, Command::FAILURE on domain or validation error
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = self::text($input->getArgument('email'));
        $name = $input->getOption('name');
        /** @var list<string> $rolesOption */
        $rolesOption = $input->getOption('role');
        $roles = [] === $rolesOption ? null : $rolesOption;
        $statusOption = $input->getOption('status');

        $status = null;
        if (null !== $statusOption) {
            $status = UserStatus::tryFrom(self::text($statusOption));
            if (null === $status) {
                $accepted = array_map(static fn (UserStatus $s): string => $s->value, UserStatus::cases());
                $io->error(\sprintf('Unknown status "%s". Accepted values: %s.', self::text($statusOption), implode(', ', $accepted)));

                return Command::FAILURE;
            }
        }

        try {
            $user = $this->userManager->updateUser(
                $email,
                null === $name ? null : self::text($name),
                $roles,
                $status,
            );
        } catch (\DomainException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Updated user "%s".', $user->getUserIdentifier()));

        return Command::SUCCESS;
    }

    /**
     * Read a console value as a string.
     *
     * `InputInterface::getArgument()` and `getOption()` are typed to
     * `mixed`, so every use needs narrowing. A blanket `(string)` cast
     * hides the case the type system is pointing at — an array option
     * would raise a conversion error rather than a usable message — so
     * anything that is not a string becomes an empty string, which the
     * caller already treats as "not supplied".
     *
     * @param mixed $value the raw console value
     *
     * @return string the value when it is a string, otherwise an empty string
     */
    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
