<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Security\UserOrganization;
use PHPUnit\Framework\TestCase;

final class UserOrganizationTest extends TestCase
{
    // Tests that a user's organisation is resolved by looking their e-mail domain up in the repository.
    public function testResolvesOrganizationFromEmailDomain(): void
    {
        $organization = $this->organization();
        $service = $this->serviceReturning(['aarhus.dk' => $organization]);

        self::assertSame($organization, $service->of($this->user('operator@AARHUS.dk')));
    }

    // Ensures a user whose domain no organisation claims resolves to null.
    public function testResolvesNullWhenNoOrganizationClaimsTheDomain(): void
    {
        $service = $this->serviceReturning([]);

        self::assertNull($service->of($this->user('operator@unclaimed.test')));
    }

    // Ensures a user without a usable e-mail never reaches the repository.
    public function testResolvesNullWhenTheUserHasNoEmail(): void
    {
        $repository = $this->createMock(OrganizationRepository::class);
        $repository->expects(self::never())->method('findOneByEmailDomain');

        self::assertNull((new UserOrganization($repository))->of(new User()));
    }

    // Verifies covers() grants when the user's derived organisation is the one asked about.
    public function testCoversTheUsersOwnOrganization(): void
    {
        $organization = $this->organization();
        $service = $this->serviceReturning(['aarhus.dk' => $organization]);

        self::assertTrue($service->covers($this->user('operator@aarhus.dk'), $organization));
    }

    // Verifies covers() denies across two different organisations.
    public function testDoesNotCoverAnotherOrganization(): void
    {
        $service = $this->serviceReturning(['aarhus.dk' => $this->organization()]);

        self::assertFalse($service->covers($this->user('operator@aarhus.dk'), $this->organization('Aalborg Kommune')));
    }

    // Ensures covers() fails closed when no organisation is supplied.
    public function testDoesNotCoverANullOrganization(): void
    {
        $service = $this->serviceReturning(['aarhus.dk' => $this->organization()]);

        self::assertFalse($service->covers($this->user('operator@aarhus.dk'), null));
    }

    // Ensures covers() fails closed when the actor's own domain resolves to nothing.
    public function testDoesNotCoverWhenTheActorHasNoOrganization(): void
    {
        $service = $this->serviceReturning([]);

        self::assertFalse($service->covers($this->user('operator@unclaimed.test'), $this->organization()));
    }

    private function user(string $email): User
    {
        $user = new User();
        $user->setEmail($email);

        return $user;
    }

    private function organization(string $name = 'Aarhus Kommune'): Organization
    {
        // AbstractITKDevEntity assigns a fresh ULID in its constructor, so
        // two instances are already distinguishable to `covers()`.
        return new Organization($name, ['aarhus.dk'], 'openwebui');
    }

    /**
     * @param array<string, Organization> $byDomain organisations keyed by the domain that resolves to them
     */
    private function serviceReturning(array $byDomain): UserOrganization
    {
        $repository = $this->createMock(OrganizationRepository::class);
        $repository->method('findOneByEmailDomain')
            ->willReturnCallback(static fn (string $domain): ?Organization => $byDomain[$domain] ?? null);

        return new UserOrganization($repository);
    }
}
