<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011010930 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the change fingerprints to agent reviews, and create the table of the checks of carried reviews';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_reviews ADD change_fingerprint VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_reviews ADD lines_fingerprint VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE TABLE agent_review_posts (id UUID NOT NULL, check_run_id BIGINT DEFAULT NULL, annotations_posted INT NOT NULL, posted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, head_sha VARCHAR(64) NOT NULL, line_notes BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, review_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_ED9F192A3E2E969B ON agent_review_posts (review_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_agent_review_posts_review_head ON agent_review_posts (review_id, head_sha)');
        $this->addSql('ALTER TABLE agent_review_posts ADD CONSTRAINT FK_ED9F192A3E2E969B FOREIGN KEY (review_id) REFERENCES agent_reviews (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE agent_review_posts');
        $this->addSql('ALTER TABLE agent_reviews DROP change_fingerprint');
        $this->addSql('ALTER TABLE agent_reviews DROP lines_fingerprint');
    }
}
