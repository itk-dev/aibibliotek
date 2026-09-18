<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Repository\OrganizationRepository;
use App\Security\AllowedEmailDomains;
use PHPUnit\Framework\TestCase;

/**
 * Unit-level cover of {@see AllowedEmailDomains}: with the source
 * of truth now living in `Organization.emailDomains`, the class
 * itself only normalises the candidate domain and delegates to
 * {@see OrganizationRepository::collectAllowedEmailDomains()}.
 * Parsing / dedup / blank-entry trimming are repository concerns
 * exercised by `OrganizationRepositoryTest` against real rows.
 */
final class AllowedEmailDomainsTest extends TestCase
{
    // Tests that an empty repository list yields an empty all() and no matches.
    public function testEmptyRepositoryProducesEmptyList(): void
    {
        $allow = $this->allowedDomains([]);

        self::assertSame([], $allow->all());
        self::assertFalse($allow->contains('aarhus.dk'));
    }

    // Verifies all() returns the repository's list verbatim.
    public function testAllDelegatesToRepository(): void
    {
        $allow = $this->allowedDomains(['aarhus.dk', 'kk.dk']);

        self::assertSame(['aarhus.dk', 'kk.dk'], $allow->all());
    }

    // Verifies contains() is case-insensitive and tolerates surrounding whitespace.
    public function testContainsIsCaseInsensitiveAndWhitespaceTolerant(): void
    {
        $allow = $this->allowedDomains(['aarhus.dk']);

        self::assertTrue($allow->contains('AARHUS.DK'));
        self::assertTrue($allow->contains('  aarhus.dk  '));
        self::assertFalse($allow->contains('other.dk'));
    }

    /**
     * @param list<string> $domains the canned repository response
     */
    private function allowedDomains(array $domains): AllowedEmailDomains
    {
        $repository = $this->createMock(OrganizationRepository::class);
        $repository->method('collectAllowedEmailDomains')->willReturn($domains);

        return new AllowedEmailDomains($repository);
    }
}
