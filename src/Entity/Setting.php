<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use ITKDev\EntityBundle\Audit\Attribute\Auditable;

/**
 * Key/value persistence for runtime-editable app settings.
 *
 * Generic by design so admin-editable settings can grow (admin
 * recipient address today; further fields as the app's
 * configuration surface widens) without a schema change per
 * setting. Typed accessors live on {@see \App\Settings\SettingsManager}
 * — callers do not talk to this entity directly.
 *
 * `name` is unique; `value` is nullable so "setting is intentionally
 * unset" is a representable state distinct from "this row hasn't
 * been inserted yet".
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'setting')]
#[ORM\UniqueConstraint(name: 'UNIQ_SETTING_NAME', fields: ['name'])]
#[Auditable]
class Setting extends AbstractEntity
{
    public function __construct(#[ORM\Column(length: 64)]
        private string $name, #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $value = null)
    {
        parent::__construct();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): static
    {
        $this->value = $value;

        return $this;
    }
}
