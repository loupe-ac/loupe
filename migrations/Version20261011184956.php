<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011184956 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create oauth_preview_tokens, the marker rows of preview sign-ins';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE oauth_preview_tokens (label VARCHAR(100) NOT NULL, card_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, access_token_id CHAR(80) NOT NULL, user_id UUID NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (access_token_id))');
        $this->addSql('CREATE INDEX IDX_8F7A8B28A76ED395 ON oauth_preview_tokens (user_id)');
        $this->addSql('CREATE INDEX IDX_8F7A8B28166D1F9C ON oauth_preview_tokens (project_id)');
        $this->addSql('CREATE INDEX IDX_8F7A8B28A76ED395166D1F9CEA750E8 ON oauth_preview_tokens (user_id, project_id, label)');
        $this->addSql('CREATE INDEX IDX_8F7A8B284ACC9A20 ON oauth_preview_tokens (card_id)');
        $this->addSql('ALTER TABLE oauth_preview_tokens ADD CONSTRAINT FK_8F7A8B282CCB2688 FOREIGN KEY (access_token_id) REFERENCES oauth2_access_token (identifier) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_preview_tokens ADD CONSTRAINT FK_8F7A8B28A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_preview_tokens ADD CONSTRAINT FK_8F7A8B28166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE oauth_preview_tokens');
    }
}
