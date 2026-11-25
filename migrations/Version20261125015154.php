<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125015154 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Let a discovery run hold its work request and who cancelled it, and let a run have no card';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discovery_runs ADD work_request_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE discovery_runs ADD cancelled_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE discovery_runs ALTER card_id DROP NOT NULL');
        $this->addSql('ALTER TABLE discovery_runs ADD CONSTRAINT FK_20F72011187B2D12 FOREIGN KEY (cancelled_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_20F72011187B2D12 ON discovery_runs (cancelled_by_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discovery_runs DROP CONSTRAINT FK_20F72011187B2D12');
        $this->addSql('DROP INDEX IDX_20F72011187B2D12');
        $this->addSql('ALTER TABLE discovery_runs DROP work_request_id');
        $this->addSql('ALTER TABLE discovery_runs DROP cancelled_by_id');
        $this->addSql('ALTER TABLE discovery_runs ALTER card_id SET NOT NULL');
    }
}
