<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029210023 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create oauth_refresh_token_rotation and seed the oauth.refresh_reuse.enabled flag';
    }

    /**
     * The installer seeds the flag, but it only ever runs once, so an instance
     * that upgrades has no row. Seeded on: the reuse repairs a lost login, and
     * the operator can switch it off.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE oauth_refresh_token_rotation (rotated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, previous_identifier CHAR(80) NOT NULL, successor_identifier CHAR(80) NOT NULL, PRIMARY KEY (previous_identifier))');
        $this->addSql('CREATE INDEX IDX_FAF7348E58EF1C6F ON oauth_refresh_token_rotation (successor_identifier)');
        $this->addSql('ALTER TABLE oauth_refresh_token_rotation ADD CONSTRAINT FK_FAF7348E2EC1D3 FOREIGN KEY (previous_identifier) REFERENCES oauth2_refresh_token (identifier) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_refresh_token_rotation ADD CONSTRAINT FK_FAF7348E58EF1C6F FOREIGN KEY (successor_identifier) REFERENCES oauth2_refresh_token (identifier) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'oauth.refresh_reuse.enabled', 'bool', 'true', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'oauth.refresh_reuse.enabled'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_refresh_token_rotation DROP CONSTRAINT FK_FAF7348E2EC1D3');
        $this->addSql('ALTER TABLE oauth_refresh_token_rotation DROP CONSTRAINT FK_FAF7348E58EF1C6F');
        $this->addSql('DROP TABLE oauth_refresh_token_rotation');
        $this->addSql("DELETE FROM feature_flag WHERE name = 'oauth.refresh_reuse.enabled'");
    }
}
