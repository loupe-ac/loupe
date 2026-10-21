<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020141638 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the surface and the native facts of a site-review comment, and the table of its screenshots';
    }

    public function up(Schema $schema): void
    {
        // The constant default adds the column with no table rewrite, so every row reads as a web note. The legacy selector and text columns stay for the previous image.
        $this->addSql('ALTER TABLE site_review_comments ADD surface VARCHAR(20) DEFAULT \'web\' NOT NULL');
        $this->addSql('ALTER TABLE site_review_comments ADD native JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE site_review_comments ALTER url DROP NOT NULL');
        $this->addSql('ALTER TABLE site_review_comments ADD CONSTRAINT site_review_comments_surface_shape CHECK ((surface = \'web\' AND url IS NOT NULL AND native IS NULL) OR (surface = \'ios-simulator\' AND url IS NULL AND native IS NOT NULL))');

        $this->addSql('CREATE TABLE site_review_screenshots (id UUID NOT NULL, content_type VARCHAR(20) NOT NULL, bytes BYTEA NOT NULL, size INT NOT NULL, width INT NOT NULL, height INT NOT NULL, comment_id UUID NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CE9D66EBF8697D13 ON site_review_screenshots (comment_id)');
        $this->addSql('CREATE INDEX IDX_CE9D66EB166D1F9C ON site_review_screenshots (project_id)');
        $this->addSql('ALTER TABLE site_review_screenshots ADD CONSTRAINT FK_CE9D66EBF8697D13 FOREIGN KEY (comment_id) REFERENCES site_review_comments (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE site_review_screenshots ADD CONSTRAINT FK_CE9D66EB166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
    }

    /** A native note has no url, so it cannot survive the NOT NULL that this puts back. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_review_screenshots DROP CONSTRAINT FK_CE9D66EBF8697D13');
        $this->addSql('ALTER TABLE site_review_screenshots DROP CONSTRAINT FK_CE9D66EB166D1F9C');
        $this->addSql('DROP TABLE site_review_screenshots');
        $this->addSql('ALTER TABLE site_review_comments DROP CONSTRAINT site_review_comments_surface_shape');
        $this->addSql('DELETE FROM site_review_comments WHERE url IS NULL');
        $this->addSql('ALTER TABLE site_review_comments ALTER url SET NOT NULL');
        $this->addSql('ALTER TABLE site_review_comments DROP native');
        $this->addSql('ALTER TABLE site_review_comments DROP surface');
    }
}
