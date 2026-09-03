<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Derive a conversion result\'s location from its id, as an upload\'s already is.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversion DROP COLUMN result_path');
    }

    /** Reversible, unlike the first migration: the column carried nothing that cannot be recomputed. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversion ADD COLUMN result_path VARCHAR(1024) DEFAULT NULL');
    }
}
