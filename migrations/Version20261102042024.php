<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102042024 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the API key id and name to verdicts, comments and decision answers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reviews ADD api_key_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE reviews ADD api_key_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE comments ADD api_key_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE comments ADD api_key_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE decision_answers ADD answered_api_key_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE decision_answers ADD answered_api_key_name VARCHAR(100) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reviews DROP api_key_id');
        $this->addSql('ALTER TABLE reviews DROP api_key_name');
        $this->addSql('ALTER TABLE comments DROP api_key_id');
        $this->addSql('ALTER TABLE comments DROP api_key_name');
        $this->addSql('ALTER TABLE decision_answers DROP answered_api_key_id');
        $this->addSql('ALTER TABLE decision_answers DROP answered_api_key_name');
    }
}
