<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020150952 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Let the loupe-cli OAuth client request the site-review scope';
    }

    /**
     * The CLI posts live review notes, so it needs `site-review`. Only the row
     * the earlier migration wrote is changed, so an operator-edited row keeps
     * its value.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE oauth2_client
            SET scopes = 'agent mcp projects site-review'
            WHERE identifier = 'loupe-cli' AND scopes = 'agent mcp projects'
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE oauth2_client
            SET scopes = 'agent mcp projects'
            WHERE identifier = 'loupe-cli' AND scopes = 'agent mcp projects site-review'
            SQL);
    }
}
