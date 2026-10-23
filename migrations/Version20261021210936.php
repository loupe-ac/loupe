<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021210936 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the legacy selector and text columns and the surface default of a site-review comment';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: the entity stopped mapping both columns in the release that added surface, and no image that maps them serves traffic.
        $this->addSql('ALTER TABLE site_review_comments DROP selector');
        // @contract-phase: the entity stopped mapping both columns in the release that added surface, and no image that maps them serves traffic.
        $this->addSql('ALTER TABLE site_review_comments DROP text');
        $this->addSql('ALTER TABLE site_review_comments ALTER surface DROP DEFAULT');
    }

    /** The dropped values repeated anchor 0, so down rebuilds them from it. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_review_comments ADD selector TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE site_review_comments ADD text TEXT DEFAULT NULL');
        $this->addSql('UPDATE site_review_comments c SET selector = a.selector, text = a.text FROM site_review_comment_anchors a WHERE a.comment_id = c.id AND a.position = 0');
        $this->addSql('ALTER TABLE site_review_comments ALTER surface SET DEFAULT \'web\'');
    }
}
