<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Security\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Console command that rewrites every user's password.
 *
 * Intended for use after copying a production data dump into a
 * non-production environment (staging, local development), or
 * for security-incident response where every user must reset.
 *
 * Two modes:
 *
 * - Default: each user gets a fresh random password. Nobody can
 *   sign in until they reset via the forgot-password flow — the
 *   safe choice for the incident-response case.
 * - `--password=…`: every user gets the same known password.
 *   Useful for a dev/staging copy of prod where an operator
 *   needs to log in as any user without going through the reset
 *   flow.
 *
 * Guarded against accidental production use: refuses to run when
 * `kernel.environment` is `prod` unless `--force` is passed.
 * Also prompts for confirmation before making changes (skip with
 * `--no-interaction` or `--force`).
 */
#[AsCommand(
    name: 'app:user:obfuscate-passwords',
    description: 'Rewrite every user\'s password with a random value (or a shared value via --password).',
)]
final class UserObfuscatePasswordsCommand extends Command
{
    /**
     * Length in bytes of the random per-user password. 32 bytes
     * → 43 base64url characters after the `=` padding is
     * stripped, well beyond any practical brute-force ceiling.
     */
    private const int RANDOM_BYTES = 32;

    /**
     * @param UserManager    $userManager    service that owns password rotation
     * @param UserRepository $userRepository iterated for the every-user list
     * @param string         $environment    Symfony kernel environment, used for the prod guard
     */
    public function __construct(
        private readonly UserManager $userManager,
        private readonly UserRepository $userRepository,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    /**
     * Declare CLI options.
     */
    protected function configure(): void
    {
        $this
            ->addOption(
                'password',
                null,
                InputOption::VALUE_REQUIRED,
                'Set every user to this shared password instead of a random per-user value.',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Skip the confirmation prompt and the production-environment guard.',
            )
        ;
    }

    /**
     * Iterate every persisted user and rewrite their password.
     *
     * @param InputInterface  $input  CLI options
     * @param OutputInterface $output console output stream
     *
     * @return int Command::SUCCESS after every user has been updated, Command::FAILURE when the environment guard or confirmation blocks the run
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $sharedPassword = $input->getOption('password');

        if ('prod' === $this->environment && !$force) {
            $io->error('Refusing to run in the prod environment. Pass --force if this is really what you want.');

            return Command::FAILURE;
        }

        $users = $this->userRepository->findAll();
        if ([] === $users) {
            $io->success('No users to rewrite.');

            return Command::SUCCESS;
        }

        $mode = null === $sharedPassword
            ? 'random per-user'
            : 'shared password';
        if (!$force && $input->isInteractive() && !$io->confirm(
            \sprintf('Rewrite passwords for %d user(s) using a %s value?', \count($users), $mode),
            false,
        )) {
            $io->warning('Aborted.');

            return Command::FAILURE;
        }

        foreach ($users as $user) {
            $plain = null === $sharedPassword
                ? self::randomPassword()
                : (string) $sharedPassword;
            $this->userManager->changePassword($user->getUserIdentifier(), $plain);
        }

        $io->success(\sprintf('Rewrote passwords for %d user(s).', \count($users)));

        return Command::SUCCESS;
    }

    /**
     * Generate a URL-safe random password.
     *
     * Uses `random_bytes()` + base64url encoding so the value is
     * safe to paste into any client. Not that anyone should be
     * pasting these — the per-user random mode is designed to
     * make the passwords *unusable* until the user resets.
     *
     * @return string a fresh URL-safe random password
     */
    private static function randomPassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::RANDOM_BYTES)), '+/', '-_'), '=');
    }
}
