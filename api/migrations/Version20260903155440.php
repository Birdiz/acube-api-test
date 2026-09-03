<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903155440 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the file and conversion tables, and the Messenger queue.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE conversion (id VARCHAR(26) NOT NULL, target_format VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, result_path VARCHAR(1024) DEFAULT NULL, error_message CLOB DEFAULT NULL, created_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, file_id VARCHAR(26) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_BD91274493CB796C FOREIGN KEY (file_id) REFERENCES file (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_BD91274493CB796C ON conversion (file_id)');
        $this->addSql('CREATE TABLE file (id VARCHAR(26) NOT NULL, original_filename VARCHAR(255) NOT NULL, source_format VARCHAR(16) NOT NULL, size_bytes INTEGER NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Dropping these tables would take the uploaded files and their conversions with them.',
        );
    }
}
