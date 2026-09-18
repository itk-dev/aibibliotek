<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename `assistant.openwebui_config` to `source_config`.
 *
 * The column became format-agnostic — it stores the uploaded config
 * reduced to its format's model, with the producing format recorded
 * in `assistant.framework` — so the OpenWebUI-specific name no longer
 * fits.
 *
 * Written against Doctrine's Schema tool API (no raw `addSql`) to
 * match the other migrations. DBAL's schema diff no longer detects
 * renames, so this drops the old column and adds the new one rather
 * than emitting a `CHANGE`. The project has no tagged release and no
 * production data — dev and test databases are rebuilt from fixtures
 * — so dropping the (test-only) column values is acceptable, matching
 * the tag-normalisation migration that dropped `assistant.tags`.
 */
final class Version20260701155151 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename assistant.openwebui_config to source_config.';
    }

    public function up(Schema $schema): void
    {
        $assistant = $schema->getTable('assistant');
        $assistant->dropColumn('openwebui_config');
        $assistant->addColumn('source_config', Types::JSON, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $assistant = $schema->getTable('assistant');
        $assistant->dropColumn('source_config');
        $assistant->addColumn('openwebui_config', Types::JSON, ['notnull' => false]);
    }
}
