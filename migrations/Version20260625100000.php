<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create the `setting` table plus its audit companion.
 *
 * Generic key/value storage backing {@see \App\Settings\SettingsManager}.
 * Layers on top of the entity-bundle baseline schema: ULID id and the
 * shared trait columns (timestamps, archivable, anonymization status,
 * blame relations) so `Setting` extends `App\Entity\AbstractEntity`
 * like every other audited domain entity. `name` is unique;
 * `value` is nullable so "intentionally unset" stays representable.
 *
 * Written against Doctrine's Schema tool API (no raw addSql) so it
 * stays portable across any database Doctrine supports.
 */
final class Version20260625100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the setting table (and its audit companion) with the entity-bundle shape.';
    }

    public function up(Schema $schema): void
    {
        $setting = $this->createEntityTable($schema, 'setting');
        $setting->addColumn('name', Types::STRING, ['length' => 64, 'notnull' => true]);
        $setting->addColumn('value', Types::TEXT, ['notnull' => false]);
        $setting->addUniqueIndex(['name'], 'UNIQ_SETTING_NAME');
        $this->addBlameRelations($setting);

        // Audit log table (damienharper/auditor doctrine provider, *_audit
        // suffix). The hashed index names are the auditor bundle's own;
        // the hash is `md5("<tablename>_audit")` and must be stable so
        // future schema diffs stay quiet.
        $this->createAuditTable($schema, 'setting_audit', '3513469ea7a666e2177c063bcd5d9c6d');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('setting_audit');
        $schema->dropTable('setting');
    }

    /**
     * Create an entity table with a ULID primary key and the entity-bundle
     * shared columns common to every audited entity.
     *
     * Adds the ULID `id`, the timestampable (`created_at`, `updated_at`),
     * archivable (`archived_at`), and anonymization (`anonymized_at`) columns
     * that the bundle's traits map. Caller-specific columns and the blame
     * relations are added by the caller afterwards.
     *
     * @param Schema $schema the schema being mutated
     * @param string $name   the table name to create
     *
     * @return Table the created table, ready for entity-specific columns
     */
    private function createEntityTable(Schema $schema, string $name): Table
    {
        $table = $schema->createTable($name);
        $table->addColumn('id', 'ulid', ['notnull' => true]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('anonymized_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addOption('charset', 'utf8mb4');

        return $table;
    }

    /**
     * Add the entity-bundle blame relations to an entity table.
     *
     * Adds the nullable `created_by_id` / `modified_by_id` ULID columns,
     * their indexes, and the `SET NULL` foreign keys to `user.id` that
     * back the BlameableTrait ManyToOne relations.
     *
     * @param Table $table the entity table to extend
     */
    private function addBlameRelations(Table $table): void
    {
        $table->addColumn('created_by_id', 'ulid', ['notnull' => false]);
        $table->addColumn('modified_by_id', 'ulid', ['notnull' => false]);
        $table->addIndex(['created_by_id']);
        $table->addIndex(['modified_by_id']);
        $table->addForeignKeyConstraint('user', ['created_by_id'], ['id'], ['onDelete' => 'SET NULL']);
        $table->addForeignKeyConstraint('user', ['modified_by_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    /**
     * Create a damienharper/auditor `*_audit` table.
     *
     * Replicates the doctrine provider's table layout: an
     * auto-incrementing audit-row id, the change metadata
     * (type, object/transaction identifiers, JSON diff), the blame
     * columns, and the client ip plus timestamp.
     *
     * @param Schema $schema    the schema being mutated
     * @param string $tableName the audit table name (`<entity>_audit`)
     * @param string $indexHash the auditor bundle's per-table
     *                          index-name hash, reused verbatim so schema
     *                          diffs stay quiet
     */
    private function createAuditTable(Schema $schema, string $tableName, string $indexHash): void
    {
        $table = $schema->createTable($tableName);
        $table->addColumn('id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => true, 'notnull' => true]);
        $table->addColumn('type', Types::STRING, ['length' => 10, 'notnull' => true]);
        $table->addColumn('object_id', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('discriminator', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('transaction_hash', Types::STRING, ['length' => 40, 'notnull' => false]);
        $table->addColumn('diffs', Types::JSON, ['notnull' => false]);
        $table->addColumn('blame_id', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('blame_user', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('blame_user_fqdn', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('blame_user_firewall', Types::STRING, ['length' => 100, 'notnull' => false]);
        $table->addColumn('ip', Types::STRING, ['length' => 45, 'notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['type'], \sprintf('type_%s_idx', $indexHash));
        $table->addIndex(['object_id'], \sprintf('object_id_%s_idx', $indexHash));
        $table->addIndex(['discriminator'], \sprintf('discriminator_%s_idx', $indexHash));
        $table->addIndex(['transaction_hash'], \sprintf('transaction_hash_%s_idx', $indexHash));
        $table->addIndex(['blame_id'], \sprintf('blame_id_%s_idx', $indexHash));
        $table->addIndex(['created_at'], \sprintf('created_at_%s_idx', $indexHash));
        $table->addOption('charset', 'utf8mb4');
    }
}
