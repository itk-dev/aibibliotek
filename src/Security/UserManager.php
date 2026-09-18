<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Create users and rotate their passwords.
 *
 * Hides the Doctrine + password-hasher wiring from callers
 * (controllers, console commands, fixtures) so they work with plain
 * strings and get a persisted {@see User} back.
 */
final class UserManager
{
    /**
     * @param EntityManagerInterface      $entityManager  Doctrine entity manager
     * @param UserRepository              $userRepository read-side lookup of users by email
     * @param UserPasswordHasherInterface $passwordHasher Symfony Security hasher
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Create a new persisted user with a hashed password.
     *
     * Defaults to `UserStatus::Pending` so accidentally omitting
     * `$status` lands a non-loggable account rather than a usable one
     * (least-privilege default). Callers that want an immediately
     * usable account — the console command and the local-development
     * fixtures — pass `UserStatus::Approved` explicitly.
     *
     * `$plainPassword` may be `null` (omitted entirely) when the
     * caller doesn't care about the credential — typical for
     * fixture seeding of subject users who won't log in through
     * this account. In that case the method mints an unguessable
     * random secret and hashes it, so the row still carries a
     * usable password hash and the login form remains resistant
     * to enumeration timing attacks. Passing an empty string is
     * still an error — it usually signals a form submission that
     * missed the required-field guard.
     *
     * @return User the persisted user with an assigned id
     *
     * @throws \DomainException          when a user with the same e-mail already exists
     * @throws \InvalidArgumentException when `$plainPassword` is the empty string
     */
    /**
     * Create a new user from an `UserCreateType` form submission.
     *
     * Thin shim that unpacks the form's associative array shape
     * and forwards to {@see createUser()} so the admin create-form
     * controller stays free of array-key plumbing. Missing keys
     * fall back to the same defaults as the underlying call.
     *
     * @param array{
     *     email?: string,
     *     name?: string,
     *     password?: string,
     *     roles?: list<string>,
     *     status?: UserStatus
     * } $input form submission payload
     *
     * @return User the persisted user with an assigned id
     *
     * @throws \DomainException          when a user with the same e-mail already exists
     * @throws \InvalidArgumentException when the password is empty
     */
    public function createFromInput(array $input): User
    {
        return $this->createUser(
            $input['email'] ?? '',
            $input['name'] ?? '',
            $input['password'] ?? '',
            $input['roles'] ?? [],
            $input['status'] ?? UserStatus::Pending,
        );
    }

    public function createUser(
        string $email,
        string $name,
        ?string $plainPassword = null,
        array $roles = [],
        UserStatus $status = UserStatus::Pending,
    ): User {
        if ('' === $plainPassword) {
            throw new \InvalidArgumentException('Password must not be empty.');
        }

        if (null !== $this->userRepository->findOneBy(['email' => $email])) {
            throw new \DomainException(\sprintf('A user with the e-mail "%s" already exists.', $email));
        }

        $user = (new User())
            ->setEmail($email)
            ->setName($name)
            ->setRoles($roles)
            ->setStatus($status);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword ?? bin2hex(random_bytes(32))));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Update an existing user's mutable identity fields.
     *
     * Each optional argument follows the same rule: `null` means
     * "leave the field untouched". When `$roles` is provided it
     * replaces the user's role list wholesale — pass an empty
     * array to clear all custom roles (the implicit `ROLE_USER`
     * floor lives on the entity and is not stored). Each role is
     * validated against {@see Roles}; an unknown identifier is
     * rejected before any change is persisted. The status string
     * is parsed with `UserStatus::tryFrom()`.
     *
     * @param string            $email  e-mail of the user to update
     * @param string|null       $name   new display name, or null to leave unchanged
     * @param list<string>|null $roles  new role list, or null to leave unchanged
     * @param UserStatus|null   $status new lifecycle status, or null to leave unchanged
     *
     * @return User the updated user
     *
     * @throws \DomainException          when no user with that e-mail exists
     * @throws \InvalidArgumentException when any provided role is not declared on {@see Roles}
     */
    public function updateUser(
        string $email,
        ?string $name = null,
        ?array $roles = null,
        ?UserStatus $status = null,
    ): User {
        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (null === $user) {
            throw new \DomainException(\sprintf('No user with the e-mail "%s" was found.', $email));
        }

        if (null !== $roles) {
            $allowed = [Roles::USER, Roles::DOMAIN_MANAGER, Roles::ADMIN];
            foreach ($roles as $role) {
                if (!\in_array($role, $allowed, true)) {
                    throw new \InvalidArgumentException(\sprintf('Unknown role "%s". Allowed roles: %s.', $role, implode(', ', $allowed)));
                }
            }
        }

        if (null !== $name) {
            $user->setName($name);
        }
        if (null !== $roles) {
            $user->setRoles($roles);
        }
        if (null !== $status) {
            $user->setStatus($status);
        }

        $this->entityManager->flush();

        return $user;
    }

    /**
     * Replace a user's password with a freshly hashed copy.
     *
     * @param string $email            e-mail of the user to update
     * @param string $newPlainPassword new clear-text password, hashed before persistence
     *
     * @return User the updated user
     *
     * @throws \DomainException          when no user with that e-mail exists
     * @throws \InvalidArgumentException when `$newPlainPassword` is empty
     */
    public function changePassword(string $email, string $newPlainPassword): User
    {
        if ('' === $newPlainPassword) {
            throw new \InvalidArgumentException('Password must not be empty.');
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (null === $user) {
            throw new \DomainException(\sprintf('No user with the e-mail "%s" was found.', $email));
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPlainPassword));
        $this->entityManager->flush();

        return $user;
    }
}
