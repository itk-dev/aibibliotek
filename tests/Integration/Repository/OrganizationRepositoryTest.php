<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrganizationRepositoryTest extends KernelTestCase
{
    // Tests that the repository is service-resolvable and round-trips a persisted Organization.
    public function testRepositoryIsResolvableAndPersistsRoundTrip(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $repository = $container->get(OrganizationRepository::class);
        self::assertInstanceOf(OrganizationRepository::class, $repository);

        $entityManager = $container->get(EntityManagerInterface::class);
        $organization = new Organization(
            'Aarhus Kommune',
            ['aarhus.dk'],
            'openwebui',
        );
        $entityManager->persist($organization);
        $entityManager->flush();

        $reloaded = $repository->find($organization->getId());

        self::assertNotNull($reloaded);
        self::assertSame('Aarhus Kommune', $reloaded->getName());
        self::assertSame(['aarhus.dk'], $reloaded->getEmailDomains());
        self::assertSame('openwebui', $reloaded->getDefaultFramework());
    }

    // Verifies the allow-list union includes every fixture organisation's domains and matches the seeded set.
    public function testCollectAllowedEmailDomainsReturnsFixtureBaseline(): void
    {
        self::bootKernel();
        $domains = self::getContainer()->get(OrganizationRepository::class)->collectAllowedEmailDomains();

        // The integration suite's OrganizationFixtures seeds aarhus,
        // aalborg (two domains), odense, and the test-friendly
        // example.test row.
        self::assertContains('aarhus.dk', $domains);
        self::assertContains('aalborg.dk', $domains);
        self::assertContains('aalborgkommune.dk', $domains);
        self::assertContains('odense.dk', $domains);
        self::assertContains('example.test', $domains);
    }

    // Tests that duplicate domains across organisations collapse to a single entry in the union list.
    public function testCollectAllowedEmailDomainsDeduplicatesAcrossOrganisations(): void
    {
        $this->persistOrganization('Dup A', ['shared.test', 'unique-a.test']);
        $this->persistOrganization('Dup B', ['shared.test', 'unique-b.test']);

        $domains = self::getContainer()->get(OrganizationRepository::class)->collectAllowedEmailDomains();

        self::assertSame(1, array_count_values($domains)['shared.test'] ?? 0, '`shared.test` must appear exactly once');
        self::assertContains('unique-a.test', $domains);
        self::assertContains('unique-b.test', $domains);
    }

    // Verifies domains are normalised to lowercase + trimmed regardless of how the admin typed them.
    public function testCollectAllowedEmailDomainsNormalisesCaseAndWhitespace(): void
    {
        $this->persistOrganization('Mixed Casing', ['  Mixed.CASE.dk  ', 'TRAILING.dk']);

        $domains = self::getContainer()->get(OrganizationRepository::class)->collectAllowedEmailDomains();

        self::assertContains('mixed.case.dk', $domains);
        self::assertContains('trailing.dk', $domains);
        self::assertNotContains('  Mixed.CASE.dk  ', $domains);
    }

    // Verifies findOneByEmailDomain() returns the seeded organisation whose emailDomains list carries the queried domain.
    public function testFindOneByEmailDomainResolvesFixtureRow(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(OrganizationRepository::class);

        $organization = $repository->findOneByEmailDomain('aarhus.dk');

        self::assertNotNull($organization);
        self::assertSame('Aarhus Kommune', $organization->getName());
    }

    // Verifies findOneByEmailDomain() is case-insensitive and trims the input.
    public function testFindOneByEmailDomainNormalisesInput(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(OrganizationRepository::class);

        $organization = $repository->findOneByEmailDomain('  AARHUS.DK  ');

        self::assertNotNull($organization);
        self::assertSame('Aarhus Kommune', $organization->getName());
    }

    // Verifies findOneByEmailDomain() returns null for a domain no organisation claims.
    public function testFindOneByEmailDomainReturnsNullForUnknownDomain(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(OrganizationRepository::class);

        self::assertNull($repository->findOneByEmailDomain('unknown.test'));
    }

    // Verifies findOneByEmailDomain() short-circuits on blank input rather than scanning every organisation.
    public function testFindOneByEmailDomainReturnsNullForBlankInput(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(OrganizationRepository::class);

        self::assertNull($repository->findOneByEmailDomain('   '));
    }

    // Tests that blank or whitespace-only entries inside an organisation's emailDomains array are dropped.
    public function testCollectAllowedEmailDomainsDropsBlankEntries(): void
    {
        $this->persistOrganization('With Blanks', ['valid.test', '', '   ']);

        $domains = self::getContainer()->get(OrganizationRepository::class)->collectAllowedEmailDomains();

        self::assertContains('valid.test', $domains);
        self::assertNotContains('', $domains);
        self::assertNotContains('   ', $domains);
    }

    /**
     * @param list<string> $emailDomains
     */
    private function persistOrganization(string $name, array $emailDomains): void
    {
        if (!self::$booted) {
            self::bootKernel();
        }
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $organization = new Organization($name, $emailDomains, 'openwebui');
        $entityManager->persist($organization);
        $entityManager->flush();
    }
}
