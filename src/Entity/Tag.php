<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\ORM\Mapping as ORM;
use ITKDev\EntityBundle\Audit\Attribute\Auditable;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tag')]
#[ORM\UniqueConstraint(name: 'UNIQ_TAG_NAME', fields: ['name'])]
#[Auditable]
class Tag extends AbstractEntity implements \Stringable
{
    #[ORM\Column(length: 255)]
    private string $name;

    public function __construct(string $name)
    {
        parent::__construct();
        $this->name = $name;
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
     * Render the tag as its name.
     *
     * Lets templates and debug output print a tag without reaching for
     * {@see self::getName()}, and keeps the entity usable wherever a
     * string is expected.
     *
     * @return string the tag name
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
