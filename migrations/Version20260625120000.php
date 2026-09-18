<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add the `openwebui_config` JSON column to `assistant`.
 *
 * Stores the OpenWebUI export JSON verbatim for assistants created
 * via the upload form. Nullable so rows pre-dating the upload flow
 * stay valid. Renamed to `source_config` by a later migration once
 * the storage became format-agnostic.
 *
 * Dated 2026-06-25 12:00 so it sequences after the entity-bundle
 * squash (`Version20260624142709`) that creates the `assistant`
 * table in the first place.
 */
final class Version20260625120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add openwebui_config JSON column to assistant.';
    }

    public function up(Schema $schema): void
    {
        $assistant = $schema->getTable('assistant');
        $assistant->addColumn('openwebui_config', Types::JSON, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $assistant = $schema->getTable('assistant');
        $assistant->dropColumn('openwebui_config');
    }
}
