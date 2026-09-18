<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Organization;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class OrganizationTest extends TestCase
{
    // Tests that the constructor stores name and default framework and normalises email domains as a list.
    public function testConstructorPopulatesFieldsAndNormalisesEmailDomains(): void
    {
        $organization = new Organization(
            'Aarhus Kommune',
            [5 => ' Aarhus.DK ', 3 => 'AAK.dk'],
            'openwebui',
        );

        self::assertInstanceOf(Ulid::class, $organization->getId());
        self::assertSame('Aarhus Kommune', $organization->getName());
        self::assertSame(['aarhus.dk', 'aak.dk'], $organization->getEmailDomains());
        self::assertSame('openwebui', $organization->getDefaultFramework());
    }

    // Tests that each setter updates the underlying field and returns the entity for chaining.
    public function testSettersMutateAndReturnStatic(): void
    {
        $organization = new Organization('Aarhus Kommune', ['aarhus.dk'], 'openwebui');

        self::assertSame($organization, $organization->setName('Aalborg Kommune'));
        self::assertSame('Aalborg Kommune', $organization->getName());

        self::assertSame($organization, $organization->setEmailDomains([9 => ' AALBORG.DK', 1 => 'aalborgkommune.dk']));
        self::assertSame(['aalborg.dk', 'aalborgkommune.dk'], $organization->getEmailDomains());

        self::assertSame($organization, $organization->setDefaultFramework('langflow'));
        self::assertSame('langflow', $organization->getDefaultFramework());
    }
}
