<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationRepository;
use App\Validator\SupportedFramework;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use ITKDev\EntityBundle\Audit\Attribute\Auditable;

#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organization')]
#[Auditable]
class Organization extends AbstractEntity
{
    /**
     * @var list<string>
     */
    #[ORM\Column(name: 'email_domains', type: Types::JSON)]
    private array $emailDomains;

    /**
     * @param list<string> $emailDomains
     */
    public function __construct(
        #[ORM\Column(length: 255)]
        private string $name,
        array $emailDomains,
        #[ORM\Column(length: 255)]
        #[SupportedFramework]
        private string $defaultFramework,
    ) {
        parent::__construct();
        $this->emailDomains = $this->normaliseDomains($emailDomains);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getEmailDomains(): array
    {
        return $this->emailDomains;
    }

    /**
     * @param list<string> $emailDomains
     */
    public function setEmailDomains(array $emailDomains): static
    {
        $this->emailDomains = $this->normaliseDomains($emailDomains);

        return $this;
    }

    public function getDefaultFramework(): string
    {
        return $this->defaultFramework;
    }

    public function setDefaultFramework(string $defaultFramework): static
    {
        $this->defaultFramework = $defaultFramework;

        return $this;
    }

    /**
     * Normalise a list of email domains to lowercase + trimmed, re-indexed as a list.
     *
     * Keeps stored values comparable to lowercased domains read off the
     * right-hand side of an e-mail address at signup time, and stops admin
     * CRUD from accidentally storing "Aarhus.DK " alongside "aarhus.dk".
     *
     * @param array<array-key, string> $domains keys are ignored; callers may pass a
     *                                           filtered array whose keys have gaps
     *
     * @return list<string>
     */
    private function normaliseDomains(array $domains): array
    {
        return array_values(array_map(
            static fn (string $domain): string => strtolower(trim($domain)),
            $domains,
        ));
    }
}
