<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016150500 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the body that a site-review note gave the card it created, to tell an untouched card from an edited one';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_site_review_comments ADD created_body TEXT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_site_review_comments DROP created_body');
    }
}
