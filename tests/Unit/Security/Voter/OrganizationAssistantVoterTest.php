<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\Assistant;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Security\Roles;
use App\Security\UserOrganization;
use App\Security\Voter\EditAssistantVoter;
use App\Security\Voter\OrganizationAssistantVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class OrganizationAssistantVoterTest extends TestCase
{
    // Tests that the voter defers on an attribute outside its three supported ones.
    public function testAbstainsForUnsupportedAttribute(): void
    {
        $voter = $this->voter([Roles::DOMAIN_MANAGER], covers: true);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($this->token($this->actor()), $this->assistant(), ['ARCHIVE_ASSISTANT']),
        );
    }

    // Tests that the voter defers when the subject is not an Assistant.
    public function testAbstainsForNonAssistantSubject(): void
    {
        $voter = $this->voter([Roles::DOMAIN_MANAGER], covers: true);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($this->token($this->actor()), new \stdClass(), [OrganizationAssistantVoter::TRANSFER]),
        );
    }

    // Ensures a token carrying no User actor is denied.
    public function testDeniesWhenActorIsNotAUser(): void
    {
        $voter = $this->voter([Roles::DOMAIN_MANAGER], covers: true);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token(null), $this->assistant(), [OrganizationAssistantVoter::TRANSFER]),
        );
    }

    // Ensures a plain signed-in user is denied even for an assistant in their own organisation.
    public function testDeniesAPlainUserInTheSameOrganization(): void
    {
        $voter = $this->voter([], covers: true);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token($this->actor()), $this->assistant(), [OrganizationAssistantVoter::TRANSFER]),
        );
    }

    // Verifies a domain manager is granted on an assistant belonging to their own organisation.
    public function testGrantsADomainManagerWithinTheirOrganization(): void
    {
        $voter = $this->voter([Roles::DOMAIN_MANAGER], covers: true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->token($this->actor()), $this->assistant(), [OrganizationAssistantVoter::TRANSFER]),
        );
    }

    // Verifies a domain manager is denied on an assistant belonging to another organisation.
    public function testDeniesADomainManagerOutsideTheirOrganization(): void
    {
        $voter = $this->voter([Roles::DOMAIN_MANAGER], covers: false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token($this->actor()), $this->assistant(), [OrganizationAssistantVoter::TRANSFER]),
        );
    }

    // Verifies a site admin is granted across organisations without the domain check running.
    public function testGrantsASiteAdminAcrossOrganizations(): void
    {
        $voter = $this->voter([Roles::DOMAIN_MANAGER, Roles::ADMIN], covers: false);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->token($this->actor()), $this->assistant(), [OrganizationAssistantVoter::TRANSFER]),
        );
    }

    // Ensures the organisational grant also covers editing, which is what "redigere på tværs af kommunen" asks for.
    public function testGrantsEditWithinTheOrganization(): void
    {
        $voter = $this->voter([Roles::DOMAIN_MANAGER], covers: true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->token($this->actor()), $this->assistant(), [EditAssistantVoter::EDIT]),
        );
    }

    // Ensures the organisational grant also covers deleting within the organisation.
    public function testGrantsDeleteWithinTheOrganization(): void
    {
        $voter = $this->voter([Roles::DOMAIN_MANAGER], covers: true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->token($this->actor()), $this->assistant(), [EditAssistantVoter::DELETE]),
        );
    }

    /**
     * The organisation every assistant in this test belongs to. Held on
     * the test case so `voter()` can hand the same instance back as the
     * actor's own organisation when the pair is meant to match.
     */
    private Organization $aarhus;

    protected function setUp(): void
    {
        $this->aarhus = new Organization('Aarhus Kommune', ['aarhus.dk'], 'openwebui');
    }

    private function assistant(): Assistant
    {
        return new Assistant('t', 'd', 'lm', 'openwebui', organization: $this->aarhus);
    }

    /**
     * @param list<string> $tokenRoles roles the access-decision manager will consider granted
     * @param bool         $covers     what `UserOrganization::covers()` answers for this actor / assistant pair
     */
    private function voter(array $tokenRoles, bool $covers): OrganizationAssistantVoter
    {
        $adm = $this->createMock(AccessDecisionManagerInterface::class);
        $adm->method('decide')->willReturnCallback(
            static fn (TokenInterface $token, array $attributes): bool => \in_array($attributes[0] ?? null, $tokenRoles, true),
        );

        // UserOrganization is final, so drive the real service through a
        // stubbed repository: the actor's domain resolves either to the
        // assistant's own organisation or to a different one.
        $organizations = $this->createMock(OrganizationRepository::class);
        $organizations->method('findOneByEmailDomain')
            ->willReturn($covers ? $this->aarhus : new Organization('Aalborg Kommune', ['aalborg.dk'], 'openwebui'));

        return new OrganizationAssistantVoter($adm, new UserOrganization($organizations));
    }

    private function actor(): User
    {
        $actor = new User();
        $actor->setEmail('operator@aarhus.dk');

        return $actor;
    }

    private function token(?User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
