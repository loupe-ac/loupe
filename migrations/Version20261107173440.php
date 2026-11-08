<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107173440 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the start refusal of a widget note with its card link';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_site_review_comments ADD start_refused VARCHAR(255) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_site_review_comments DROP start_refused');
    }
}
