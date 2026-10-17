<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017154818 extends AbstractMigration
{
    private const array QUEUE_RENAMES = [
        'default' => 'jobs',
        'workflow' => 'card_eval',
        'async_low' => 'card_sweep',
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'Move the waiting messenger rows from the old queue names default, workflow and async_low to jobs, card_eval and card_sweep';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (self::QUEUE_RENAMES as $old => $new) {
            $this->addSql('UPDATE messenger_messages SET queue_name = :new WHERE queue_name = :old', ['old' => $old, 'new' => $new]);
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        foreach (self::QUEUE_RENAMES as $old => $new) {
            $this->addSql('UPDATE messenger_messages SET queue_name = :old WHERE queue_name = :new', ['old' => $old, 'new' => $new]);
        }
    }
}
