<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030155249 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Tag refresh tokens with their login and record the login on each bridge';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE oauth_refresh_token_login (login_id UUID NOT NULL, refresh_token_identifier CHAR(80) NOT NULL, PRIMARY KEY (refresh_token_identifier))');
        $this->addSql('CREATE INDEX idx_oauth_refresh_token_login_login ON oauth_refresh_token_login (login_id)');
        $this->addSql('ALTER TABLE oauth_refresh_token_login ADD CONSTRAINT FK_8092D8FF6DF2C412 FOREIGN KEY (refresh_token_identifier) REFERENCES oauth2_refresh_token (identifier) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridges ADD login_access_token_id VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD login_id UUID DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_refresh_token_login DROP CONSTRAINT FK_8092D8FF6DF2C412');
        $this->addSql('DROP TABLE oauth_refresh_token_login');
        $this->addSql('ALTER TABLE bridges DROP login_access_token_id');
        $this->addSql('ALTER TABLE bridges DROP login_id');
    }
}
