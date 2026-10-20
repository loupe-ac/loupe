<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020102311 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Rename the widget OAuth client from site-review to live-review';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE oauth2_client SET name = 'Loupe live-review widget' WHERE identifier = 'loupe-site-review-widget' AND name = 'Loupe site-review widget'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE oauth2_client SET name = 'Loupe site-review widget' WHERE identifier = 'loupe-site-review-widget' AND name = 'Loupe live-review widget'");
    }
}
