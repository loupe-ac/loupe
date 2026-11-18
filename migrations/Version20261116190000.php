<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116190000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Switch on the workflow.merge_queued_evaluations flag on an instance that holds it off';
    }

    /**
     * Version20261030150642 seeded the row off on each upgraded instance, so an insert does
     * nothing there and the row needs an update. A fresh install has the row on from /install.
     */
    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE feature_flag SET value = 'true' WHERE name = 'workflow.merge_queued_evaluations'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE feature_flag SET value = 'false' WHERE name = 'workflow.merge_queued_evaluations'");
    }
}
