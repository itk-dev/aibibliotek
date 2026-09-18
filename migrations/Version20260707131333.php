<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707131333 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set organization FK on assistant to ON DELETE SET NULL so removing an organisation nullifies the column rather than blocking the delete.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE assistant DROP FOREIGN KEY `FK_C2997CD132C8A3DE`');
        $this->addSql('ALTER TABLE assistant ADD CONSTRAINT FK_C2997CD132C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE assistant DROP FOREIGN KEY FK_C2997CD132C8A3DE');
        $this->addSql('ALTER TABLE assistant ADD CONSTRAINT `FK_C2997CD132C8A3DE` FOREIGN KEY (organization_id) REFERENCES organization (id)');
    }
}
