<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707084856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tagline, knowledge_description, data_sensitivity, organization_id columns to assistant.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE assistant ADD tagline VARCHAR(255) DEFAULT NULL, ADD knowledge_description LONGTEXT DEFAULT NULL, ADD data_sensitivity VARCHAR(32) DEFAULT NULL, ADD organization_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE assistant ADD CONSTRAINT FK_C2997CD132C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id)');
        $this->addSql('CREATE INDEX IDX_C2997CD132C8A3DE ON assistant (organization_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE assistant DROP FOREIGN KEY FK_C2997CD132C8A3DE');
        $this->addSql('DROP INDEX IDX_C2997CD132C8A3DE ON assistant');
        $this->addSql('ALTER TABLE assistant DROP tagline, DROP knowledge_description, DROP data_sensitivity, DROP organization_id');
    }
}
