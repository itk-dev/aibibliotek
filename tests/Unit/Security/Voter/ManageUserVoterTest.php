<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\User;
use App\Security\Roles;
use App\Security\Voter\ManageUserVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class ManageUserVoterTest extends TestCase
{
    // Tests that the voter denies access when the token has no User actor.
    public function testDeniesWhenActorIsNotAUser(): void
    {
        $voter = $this->voterAllowing();
        $token = $this->tokenFor(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $this->userWithEmail('alice@aarhus.dk'), [ManageUserVoter::MANAGE]),
        );
    }

    // Ensures access is denied when the actor lacks ROLE_DOMAIN_MANAGER even within the same domain.
    public function testDeniesWhenActorLacksDomainManagerRole(): void
    {
        $voter = $this->voterWithRoles([]);
        $token = $this->tokenFor($this->userWithEmail('charlie@aarhus.dk'));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $this->userWithEmail('alice@aarhus.dk'), [ManageUserVoter::MANAGE]),
        );
    }

    // Verifies ROLE_ADMIN short-circuits the same-domain check and grants across any domain.
    public function testGrantsAdminAcrossDomains(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER, Roles::ADMIN]);
        $token = $this->tokenFor($this->userWithEmail('siteadmin@aarhus.dk'));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $this->userWithEmail('subject@aalborg.dk'), [ManageUserVoter::MANAGE]),
        );
    }

    // Tests that a domain manager can act on a subject in the same email domain.
    public function testGrantsDomainManagerWithinSameDomain(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER]);
        $token = $this->tokenFor($this->userWithEmail('manager@aarhus.dk'));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $this->userWithEmail('alice@aarhus.dk'), [ManageUserVoter::APPROVE]),
        );
    }

    // Ensures a domain manager cannot act on subjects in a different email domain.
    public function testDeniesDomainManagerAcrossDifferentDomains(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER]);
        $token = $this->tokenFor($this->userWithEmail('manager@aarhus.dk'));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $this->userWithEmail('alice@aalborg.dk'), [ManageUserVoter::BLOCK]),
        );
    }

    // Verifies the domain comparison is case-insensitive on both actor and subject.
    public function testIsCaseInsensitiveOnTheDomainComparison(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER]);
        $token = $this->tokenFor($this->userWithEmail('manager@Aarhus.DK'));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $this->userWithEmail('alice@AARHUS.dk'), [ManageUserVoter::MANAGE]),
        );
    }

    // Tests that the voter denies when the subject has no email to derive a domain from.
    public function testDeniesWhenSubjectHasNoEmail(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER]);
        $token = $this->tokenFor($this->userWithEmail('manager@aarhus.dk'));
        $subject = new User(); // email left null

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $subject, [ManageUserVoter::MANAGE]),
        );
    }

    // Tests that the voter denies when the actor has no email to derive a domain from.
    public function testDeniesWhenActorHasNoEmail(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER]);
        $token = $this->tokenFor(new User());

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $this->userWithEmail('alice@aarhus.dk'), [ManageUserVoter::MANAGE]),
        );
    }

    // Ensures the voter abstains on attributes it doesn't claim to support.
    public function testAbstainsOnUnsupportedAttribute(): void
    {
        $voter = $this->voterAllowing();
        $token = $this->tokenFor($this->userWithEmail('alice@aarhus.dk'));

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($token, $this->userWithEmail('bob@aarhus.dk'), ['SOMETHING_ELSE']),
        );
    }

    // Ensures the voter abstains on subjects that aren't User instances.
    public function testAbstainsOnUnsupportedSubject(): void
    {
        $voter = $this->voterAllowing();
        $token = $this->tokenFor($this->userWithEmail('alice@aarhus.dk'));

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($token, new \stdClass(), [ManageUserVoter::MANAGE]),
        );
    }

    // Verifies an admin can promote any user (any domain) to manager.
    public function testAdminCanPromoteAcrossDomainsToManager(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER, Roles::ADMIN]);
        $token = $this->tokenFor($this->userWithEmail('admin@aarhus.dk'));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $this->userWithEmail('alice@aalborg.dk'), [ManageUserVoter::PROMOTE_TO_MANAGER]),
        );
    }

    // Verifies an admin can promote any user (any domain) to admin.
    public function testAdminCanPromoteAcrossDomainsToAdmin(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER, Roles::ADMIN]);
        $token = $this->tokenFor($this->userWithEmail('admin@aarhus.dk'));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $this->userWithEmail('alice@aalborg.dk'), [ManageUserVoter::PROMOTE_TO_ADMIN]),
        );
    }

    // Ensures a manager may NOT promote anyone to admin, even within their own domain.
    public function testManagerCannotPromoteToAdminEvenInSameDomain(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER]);
        $token = $this->tokenFor($this->userWithEmail('manager@aarhus.dk'));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $this->userWithEmail('alice@aarhus.dk'), [ManageUserVoter::PROMOTE_TO_ADMIN]),
        );
    }

    // Verifies a manager can promote a same-domain plain user to manager.
    public function testManagerCanPromoteSameDomainUserToManager(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER]);
        $token = $this->tokenFor($this->userWithEmail('manager@aarhus.dk'));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $this->userWithEmail('alice@aarhus.dk'), [ManageUserVoter::PROMOTE_TO_MANAGER]),
        );
    }

    // Tests the headline rule: a manager cannot touch an admin even within their own domain.
    public function testManagerCannotPromoteAdminToManagerEvenInSameDomain(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER]);
        $token = $this->tokenFor($this->userWithEmail('manager@aarhus.dk'));
        $admin = $this->userWithRolesAndEmail([Roles::ADMIN], 'admin@aarhus.dk');

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $admin, [ManageUserVoter::PROMOTE_TO_MANAGER]),
        );
    }

    // Reverse case of the headline rule: a manager cannot demote an admin even in their own domain.
    public function testManagerCannotDemoteAdminEvenInSameDomain(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER]);
        $token = $this->tokenFor($this->userWithEmail('manager@aarhus.dk'));
        $admin = $this->userWithRolesAndEmail([Roles::ADMIN], 'admin@aarhus.dk');

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $admin, [ManageUserVoter::DEMOTE]),
        );
    }

    // Ensures cross-domain managers cannot demote even ordinary users.
    public function testManagerCannotDemoteAcrossDomains(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER]);
        $token = $this->tokenFor($this->userWithEmail('manager@aarhus.dk'));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $this->userWithEmail('alice@aalborg.dk'), [ManageUserVoter::DEMOTE]),
        );
    }

    // Verifies a manager can demote a same-domain plain user (clear elevated roles).
    public function testManagerCanDemoteSameDomainUser(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER]);
        $token = $this->tokenFor($this->userWithEmail('manager@aarhus.dk'));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $this->userWithEmail('alice@aarhus.dk'), [ManageUserVoter::DEMOTE]),
        );
    }

    // Verifies an admin can demote another admin (the last-admin invariant is a service-layer concern, not voter-layer).
    public function testAdminCanDemoteAnotherAdmin(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER, Roles::ADMIN]);
        $token = $this->tokenFor($this->userWithEmail('admin@aarhus.dk'));
        $target = $this->userWithRolesAndEmail([Roles::ADMIN], 'other@aalborg.dk');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $target, [ManageUserVoter::DEMOTE]),
        );
    }

    // Ensures a manager cannot block an admin even within their own domain — blocking would lock the admin out despite keeping the role list intact.
    public function testManagerCannotBlockAdminEvenInSameDomain(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER]);
        $token = $this->tokenFor($this->userWithEmail('manager@aarhus.dk'));
        $admin = $this->userWithRolesAndEmail([Roles::ADMIN], 'admin@aarhus.dk');

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $admin, [ManageUserVoter::BLOCK]),
        );
    }

    // Ensures a manager cannot approve an admin even within their own domain — same uniform "manager cannot touch admin" rule.
    public function testManagerCannotApproveAdminEvenInSameDomain(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER]);
        $token = $this->tokenFor($this->userWithEmail('manager@aarhus.dk'));
        $admin = $this->userWithRolesAndEmail([Roles::ADMIN], 'admin@aarhus.dk');

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $admin, [ManageUserVoter::APPROVE]),
        );
    }

    // Ensures the umbrella MANAGE attribute is also blocked when the actor is a manager and the target is an admin.
    public function testManagerCannotUseUmbrellaManageOnAdminEvenInSameDomain(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER]);
        $token = $this->tokenFor($this->userWithEmail('manager@aarhus.dk'));
        $admin = $this->userWithRolesAndEmail([Roles::ADMIN], 'admin@aarhus.dk');

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $admin, [ManageUserVoter::MANAGE]),
        );
    }

    // Verifies an admin can still block another admin — the manager-cannot-touch-admin rule does not apply to admin actors.
    public function testAdminCanBlockAnotherAdmin(): void
    {
        $voter = $this->voterWithRoles([Roles::DOMAIN_MANAGER, Roles::ADMIN]);
        $token = $this->tokenFor($this->userWithEmail('admin@aarhus.dk'));
        $target = $this->userWithRolesAndEmail([Roles::ADMIN], 'other@aalborg.dk');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $target, [ManageUserVoter::BLOCK]),
        );
    }

    private function userWithEmail(string $email): User
    {
        $user = new User();
        $user->setEmail($email);

        return $user;
    }

    /**
     * @param list<string> $roles
     */
    private function userWithRolesAndEmail(array $roles, string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);

        return $user;
    }

    /**
     * @param list<string> $tokenRoles roles the access-decision manager will consider granted
     */
    private function voterWithRoles(array $tokenRoles): ManageUserVoter
    {
        $adm = $this->createMock(AccessDecisionManagerInterface::class);
        $adm->method('decide')->willReturnCallback(
            static fn (TokenInterface $token, array $attributes): bool => \in_array($attributes[0] ?? null, $tokenRoles, true),
        );

        return new ManageUserVoter($adm);
    }

    private function voterAllowing(): ManageUserVoter
    {
        // For tests that never reach the role check (unsupported attribute / subject
        // and "actor is not a User"), the access-decision manager isn't consulted.
        return $this->voterWithRoles([Roles::DOMAIN_MANAGER, Roles::ADMIN]);
    }

    private function tokenFor(?User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
