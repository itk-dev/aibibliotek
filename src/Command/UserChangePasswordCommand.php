<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Security\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command that updates an existing user's password.
 *
 * Thin adapter over {@see UserManager::changePassword()}.
 *
 * The new password is never a CLI argument. Anything passed on the
 * command line survives in shell history, is readable in the process
 * list for as long as the command runs, and is echoed into deployment
 * logs, so the secret is prompted for instead and hidden while typed.
 * The e-mail may still be passed as an argument; when omitted it is
 * prompted for with completion over the existing addresses, since
 * rotating a password usually means finding the account first.
 */
#[AsCommand(
    name: 'app:user:change-password',
    description: 'Set a new password for an existing user, prompting for the password.',
)]
final class UserChangePasswordCommand extends Command
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
     * @param UserManager    $userManager    service that owns password rotation
     * @param UserRepository $userRepository read-side lookup backing the e-mail completion
     */
    public function __construct(
        private readonly UserManager $userManager,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    /**
     * Declare CLI arguments.
     *
     * The e-mail is optional so the command can be run bare and offer
     * the known addresses instead of expecting one to be remembered.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'The e-mail of the user whose password to change. Prompted for, with completion, when omitted.');
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
            $question = new Question('E-mail address');
            $question->setAutocompleterValues($this->userRepository->collectEmails());

            $input->setArgument('email', $io->askQuestion($question));
        }

        $this->password = self::text($io->askHidden('New password (input hidden)'));
    }

    /**
     * Adapt console arguments to the {@see UserManager} call.
     *
     * @param InputInterface  $input  CLI arguments
     * @param OutputInterface $output console output stream
     *
     * @return int Command::SUCCESS on a successful change, Command::FAILURE on a
     *             missing prompt, domain or validation error
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = self::text($input->getArgument('email'));
        $password = $this->password ?? '';

        if ('' === $email || '' === $password) {
            $io->error('E-mail and password are both required. The password is only ever collected interactively, so this command cannot run with --no-interaction.');

            return Command::FAILURE;
        }

        try {
            $user = $this->userManager->changePassword($email, $password);
        } catch (\DomainException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Updated password for user "%s".', $user->getUserIdentifier()));

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
