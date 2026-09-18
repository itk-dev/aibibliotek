<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\Assistant;
use App\Entity\User;
use App\Security\Roles;
use App\Security\Voter\EditAssistantVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class EditAssistantVoterTest extends TestCase
{
    // Defers when the attribute is anything other than EDIT_ASSISTANT or DELETE_ASSISTANT.
    public function testAbstainsForUnsupportedAttribute(): void
    {
        $voter = $this->voterWithRoles([]);
        $token = $this->tokenFor($this->user());

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($token, $this->assistantOwnedBy($this->user()), ['ARCHIVE_ASSISTANT']),
        );
    }

    // Grants the assistant's author the DELETE_ASSISTANT attribute — same rule as EDIT_ASSISTANT.
    public function testGrantsDeleteToTheAssistantAuthor(): void
    {
        $author = $this->user();
        $voter = $this->voterWithRoles([]);
        $token = $this->tokenFor($author);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $this->assistantOwnedBy($author), [EditAssistantVoter::DELETE]),
        );
    }

    // Defers when the subject is not an Assistant.
    public function testAbstainsForNonAssistantSubject(): void
    {
        $voter = $this->voterWithRoles([]);
        $token = $this->tokenFor($this->user());

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($token, new \stdClass(), [EditAssistantVoter::EDIT]),
        );
    }

    // Denies when the token carries no User actor (anonymous / API-token / etc.).
    public function testDeniesWhenActorIsNotAUser(): void
    {
        $voter = $this->voterWithRoles([]);
        $token = $this->tokenFor(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $this->assistantOwnedBy($this->user()), [EditAssistantVoter::EDIT]),
        );
    }

    // Grants site admins across every assistant regardless of authorship.
    public function testGrantsAdminOnAnyAssistant(): void
    {
        $voter = $this->voterWithRoles([Roles::ADMIN]);
        $token = $this->tokenFor($this->user());
        $other = $this->user();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $this->assistantOwnedBy($other), [EditAssistantVoter::EDIT]),
        );
    }

    // Grants the assistant's original curator.
    public function testGrantsTheAssistantAuthor(): void
    {
        $author = $this->user();
        $voter = $this->voterWithRoles([]);
        $token = $this->tokenFor($author);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $this->assistantOwnedBy($author), [EditAssistantVoter::EDIT]),
        );
    }

    // Denies a signed-in user who is neither the author nor an admin.
    public function testDeniesOtherUsers(): void
    {
        $author = $this->user();
        $bystander = $this->user();
        $voter = $this->voterWithRoles([]);
        $token = $this->tokenFor($bystander);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $this->assistantOwnedBy($author), [EditAssistantVoter::EDIT]),
        );
    }

    // Denies when the assistant carries no `createdBy` blame (legacy row / anonymous seed).
    public function testDeniesWhenAssistantHasNoAuthor(): void
    {
        $voter = $this->voterWithRoles([]);
        $token = $this->tokenFor($this->user());
        $assistant = new Assistant('t', 'd', 'lm', 'openwebui');

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $assistant, [EditAssistantVoter::EDIT]),
        );
    }

    private function user(): User
    {
        return new User();
    }

    private function assistantOwnedBy(User $author): Assistant
    {
        $assistant = new Assistant('t', 'd', 'lm', 'openwebui');
        $assistant->setCreatedBy($author);

        return $assistant;
    }

    /**
     * @param list<string> $tokenRoles roles the access-decision manager will consider granted
     */
    private function voterWithRoles(array $tokenRoles): EditAssistantVoter
    {
        $adm = $this->createMock(AccessDecisionManagerInterface::class);
        $adm->method('decide')->willReturnCallback(
            static fn (TokenInterface $token, array $attributes): bool => \in_array($attributes[0] ?? null, $tokenRoles, true),
        );

        return new EditAssistantVoter($adm);
    }

    private function tokenFor(?User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
