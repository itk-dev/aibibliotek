<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\DataFixtures\UserFixtures;
use App\Entity\Assistant;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\AssistantRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration coverage of the two queries the ownership screen adds:
 * an organisation's assistants, and the users an assistant may be
 * handed to within it.
 */
final class AssistantOwnershipQueriesTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    // Tests that findByOrganization returns only the given organisation's assistants, A→Z by title.
    public function testFindByOrganizationScopesAndOrders(): void
    {
        $aarhus = $this->organization('Aarhus Kommune');

        $rows = self::getContainer()->get(AssistantRepository::class)->findByOrganization($aarhus);

        self::assertNotEmpty($rows);
        foreach ($rows as $assistant) {
            self::assertSame((string) $aarhus->getId(), (string) $assistant->getOrganization()?->getId());
        }

        $titles = array_map(static fn (Assistant $a): string => $a->getTitle(), $rows);
        $sorted = $titles;
        sort($sorted);
        self::assertSame($sorted, $titles, 'Rows must come back alphabetically by title.');
    }

    // Ensures an organisation nobody has shared an assistant for yields an empty list.
    public function testFindByOrganizationReturnsEmptyForAnUnusedOrganization(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $unused = new Organization('Ubrugt Kommune', ['ubrugt.test'], 'openwebui');
        $entityManager->persist($unused);
        $entityManager->flush();

        self::assertSame([], self::getContainer()->get(AssistantRepository::class)->findByOrganization($unused));
    }

    // Tests that findApprovedForOrganization returns approved users on the organisation's domains only.
    public function testFindApprovedForOrganizationScopesByDomainAndStatus(): void
    {
        $rows = self::getContainer()->get(UserRepository::class)
            ->findApprovedForOrganization($this->organization('Aarhus Kommune'));

        $emails = array_map(static fn (User $u): string => (string) $u->getEmail(), $rows);
        self::assertContains(UserFixtures::COLLEAGUE_EMAIL, $emails);
        self::assertContains(UserFixtures::DOMAIN_MANAGER_EMAIL, $emails);
        self::assertNotContains(UserFixtures::ALICE_EMAIL, $emails, 'A user on another domain must not be returned.');
        self::assertNotContains(UserFixtures::PENDING_EMAIL, $emails, 'A pending user must not be returned.');
        self::assertNotContains(UserFixtures::BLOCKED_EMAIL, $emails, 'A blocked user must not be returned.');
    }

    // Ensures an organisation declaring no domains matches nobody rather than everybody.
    public function testFindApprovedForOrganizationReturnsEmptyWithoutDomains(): void
    {
        $domainless = new Organization('Uden domæne', [], 'openwebui');

        self::assertSame([], self::getContainer()->get(UserRepository::class)->findApprovedForOrganization($domainless));
    }

    private function organization(string $name): Organization
    {
        $organization = self::getContainer()->get(OrganizationRepository::class)->findOneBy(['name' => $name]);
        \assert($organization instanceof Organization, 'OrganizationFixtures must seed '.$name.'.');

        return $organization;
    }
}
