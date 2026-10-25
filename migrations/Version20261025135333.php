<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025135333 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the notification_preferences table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_preferences (id UUID NOT NULL, event VARCHAR(40) NOT NULL, channel VARCHAR(40) NOT NULL, enabled BOOLEAN NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3CAA95B47E3C61F9 ON notification_preferences (owner_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_preference_cell ON notification_preferences (owner_id, event, channel)');
        $this->addSql('ALTER TABLE notification_preferences ADD CONSTRAINT FK_3CAA95B47E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_preferences DROP CONSTRAINT FK_3CAA95B47E3C61F9');
        $this->addSql('DROP TABLE notification_preferences');
    }
}
