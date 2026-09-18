<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    /**
     * Load the row for a setting key.
     *
     * Returns the `Setting` entity matching the given name, or
     * `null` when no row has been written yet. Callers that need a
     * typed value should go through {@see \App\Settings\SettingsManager}
     * — this repository only exposes the raw row.
     *
     * @param string $name canonical setting key (e.g. `admin_recipient`)
     *
     * @return Setting|null matching row, or null when unset
     */
    public function findOneByName(string $name): ?Setting
    {
        return $this->findOneBy(['name' => $name]);
    }
}
