<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Normalise assistant tags into a relational `Tag` entity.
 *
 * Creates the entity-bundle `tag` table (ULID id, shared trait columns,
 * unique `name`, blame relations) plus its `tag_audit` log and the
 * `assistant_tag` join table, then drops the now-redundant `assistant.tags`
 * JSON column. The project has no tagged release and no production data, so
 * the JSON values are dropped rather than backfilled — dev and test databases
 * are rebuilt from fixtures.
 *
 * Written against Doctrine's Schema tool API (no raw `addSql`) to match the
 * entity-bundle baseline migration. As an all-`createTable` change there is no
 * cross-table column retype, so Doctrine creates `tag` before wiring the
 * `assistant_tag` foreign key that references it.
 */
final class Version20260626112645 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalise assistant tags into a Tag entity, join table, and audit table; drop the tags JSON column.';
    }

    public function up(Schema $schema): void
    {
        $tag = $this->createEntityTable($schema, 'tag');
        $tag->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
        $tag->addUniqueIndex(['name'], 'UNIQ_TAG_NAME');
        $this->addBlameRelations($tag);

        // Index names mirror the auditor bundle's own (md5 of the table name);
        // keep them verbatim so future schema diffs stay quiet.
        $this->createAuditTable($schema, 'tag_audit', '393c773bd22d1a1a39721c0537b3631b');

        $join = $schema->createTable('assistant_tag');
        $join->addColumn('assistant_id', 'ulid', ['notnull' => true]);
        $join->addColumn('tag_id', 'ulid', ['notnull' => true]);
        $join->setPrimaryKey(['assistant_id', 'tag_id']);
        $join->addIndex(['assistant_id']);
        $join->addIndex(['tag_id']);
        $join->addForeignKeyConstraint('assistant', ['assistant_id'], ['id'], ['onDelete' => 'CASCADE']);
        $join->addForeignKeyConstraint('tag', ['tag_id'], ['id'], ['onDelete' => 'CASCADE']);
        $join->addOption('charset', 'utf8mb4');

        $schema->getTable('assistant')->dropColumn('tags');
    }

    public function down(Schema $schema): void
    {
        // Drop the join before `tag`, since it carries the foreign key into it.
        $schema->dropTable('assistant_tag');
        $schema->dropTable('tag_audit');
        $schema->dropTable('tag');

        $schema->getTable('assistant')->addColumn('tags', Types::JSON, ['notnull' => true]);
    }

    /**
     * Create an entity-bundle base table.
     *
     * Adds the ULID primary key and the shared trait columns (timestamps,
     * archivable, anonymization status) every `AbstractEntity` carries, plus
     * the utf8mb4 charset. Callers add their entity-specific columns and the
     * blame relations on the returned table.
     *
     * @param Schema $schema the schema being mutated
     * @param string $name   the table name to create
     *
     * @return Table the created table, ready for further columns
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
     * Adds the nullable `created_by_id` / `modified_by_id` ULID columns, their
     * indexes, and the `SET NULL` foreign keys to `user.id` that back the
     * BlameableTrait ManyToOne relations.
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
     * Replicates the doctrine provider's table layout: an auto-incrementing
     * audit-row id, the change metadata (type, object/transaction identifiers,
     * JSON diff), the blame columns, and the client ip plus timestamp.
     *
     * @param Schema $schema    the schema being mutated
     * @param string $tableName the audit table name (`<entity>_audit`)
     * @param string $indexHash the auditor bundle's per-table index-name hash,
     *                          reused verbatim so schema diffs stay quiet
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
        $table->addIndex(['type'], sprintf('type_%s_idx', $indexHash));
        $table->addIndex(['object_id'], sprintf('object_id_%s_idx', $indexHash));
        $table->addIndex(['discriminator'], sprintf('discriminator_%s_idx', $indexHash));
        $table->addIndex(['transaction_hash'], sprintf('transaction_hash_%s_idx', $indexHash));
        $table->addIndex(['blame_id'], sprintf('blame_id_%s_idx', $indexHash));
        $table->addIndex(['created_at'], sprintf('created_at_%s_idx', $indexHash));
        $table->addOption('charset', 'utf8mb4');
    }
}
