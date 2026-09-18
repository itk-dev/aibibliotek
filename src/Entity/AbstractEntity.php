<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use ITKDev\EntityBundle\Entity\AbstractITKDevEntity;
use ITKDev\EntityBundle\Entity\Contract\AnonymizationStatusInterface;
use ITKDev\EntityBundle\Entity\Contract\ArchivableInterface;
use ITKDev\EntityBundle\Entity\Contract\BlameableInterface;
use ITKDev\EntityBundle\Entity\Contract\TimestampableInterface;
use ITKDev\EntityBundle\Entity\Trait\AnonymizationStatusTrait;
use ITKDev\EntityBundle\Entity\Trait\ArchivableTrait;
use ITKDev\EntityBundle\Entity\Trait\BlameableTrait;
use ITKDev\EntityBundle\Entity\Trait\TimestampableTrait;

/**
 * Project base for every persisted domain entity.
 *
 * Extends the bundle's {@see AbstractITKDevEntity} (ULID identity + the
 * #[ITKDevEntity] discovery marker) and composes the cross-cutting concerns the
 * catalogue applies uniformly: created/updated timestamps, created-by/modified-by
 * blame, archivability, and anonymization status. Soft delete is deliberately not
 * mixed in — the application archives rather than soft-deletes (see docs/adr/007).
 *
 * Concrete entities extend this class, mark themselves #[Auditable] to opt into
 * audit logging, and annotate PII properties with #[Anonymize]. Subclasses that
 * declare their own constructor must call parent::__construct() so the ULID is
 * assigned at instantiation.
 */
#[ORM\MappedSuperclass]
abstract class AbstractEntity extends AbstractITKDevEntity implements TimestampableInterface, BlameableInterface, ArchivableInterface, AnonymizationStatusInterface
{
    use TimestampableTrait;
    use BlameableTrait;
    use ArchivableTrait;
    use AnonymizationStatusTrait;
}
