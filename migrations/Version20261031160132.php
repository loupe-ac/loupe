<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031160132 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the Try now overrides of bridge accounts and the account limit episodes of the inbox, and let a bridge command name an account instead of a run';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_account_try_now (checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, still_limited BOOLEAN DEFAULT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, account VARCHAR(40) NOT NULL, pressed_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, bridge_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4E5896B77E3C61F94948DF55 ON bridge_account_try_now (owner_id, bridge_id)');
        $this->addSql('CREATE INDEX idx_bridge_account_try_now_bridge ON bridge_account_try_now (owner_id, bridge_id, account, pressed_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_account_try_now_open ON bridge_account_try_now (owner_id, bridge_id, account) WHERE (closed_at IS NULL)');
        $this->addSql('ALTER TABLE bridge_account_try_now ADD CONSTRAINT FK_4E5896B77E3C61F94948DF55 FOREIGN KEY (owner_id, bridge_id) REFERENCES bridges (owner_id, id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE inbox_account_limits (id UUID NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, account VARCHAR(40) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, item_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A6CC4930166D1F9C ON inbox_account_limits (project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A6CC4930126F525E ON inbox_account_limits (item_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inbox_account_limits_open_account ON inbox_account_limits (project_id, account) WHERE (ended_at IS NULL)');
        $this->addSql('ALTER TABLE inbox_account_limits ADD CONSTRAINT FK_A6CC4930166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_account_limits ADD CONSTRAINT FK_A6CC4930126F525E FOREIGN KEY (item_id) REFERENCES inbox_items (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('ALTER TABLE bridge_commands ADD account VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_commands ALTER worker_run_id DROP NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM bridge_commands WHERE worker_run_id IS NULL');
        $this->addSql('ALTER TABLE bridge_commands ALTER worker_run_id SET NOT NULL');
        $this->addSql('ALTER TABLE bridge_commands DROP account');
        $this->addSql('DROP TABLE inbox_account_limits');
        $this->addSql('DROP TABLE bridge_account_try_now');
    }
}
