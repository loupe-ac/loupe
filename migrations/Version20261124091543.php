<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Module\Workflow\Template\TemplateVersion;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261124091543 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the decision log of a card, mark the card when a decision changes, and write the shipped Lifecycle copy, with the rule for a changed decision, into every Lifecycle binding';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_decisions (id UUID NOT NULL, changed_choice TEXT DEFAULT NULL, changed_note TEXT DEFAULT NULL, changed_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, addressed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, addressed_run_id UUID DEFAULT NULL, label VARCHAR(40) NOT NULL, choice TEXT NOT NULL, other_options JSONB NOT NULL, made_by VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, item_id UUID DEFAULT NULL, changed_by_id UUID DEFAULT NULL, project_id UUID NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_19CAC764126F525E ON inbox_decisions (item_id)');
        $this->addSql('CREATE INDEX IDX_19CAC764828AD0A0 ON inbox_decisions (changed_by_id)');
        $this->addSql('CREATE INDEX IDX_19CAC764166D1F9C ON inbox_decisions (project_id)');
        $this->addSql('CREATE INDEX IDX_19CAC7644ACC9A20 ON inbox_decisions (card_id)');
        $this->addSql('CREATE INDEX idx_inbox_decisions_card_created ON inbox_decisions (card_id, created_at)');
        $this->addSql('ALTER TABLE inbox_decisions ADD CONSTRAINT FK_19CAC764126F525E FOREIGN KEY (item_id) REFERENCES inbox_items (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_decisions ADD CONSTRAINT FK_19CAC764828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_decisions ADD CONSTRAINT FK_19CAC764166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_decisions ADD CONSTRAINT FK_19CAC7644ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TRIGGER workflow_mark_inbox_decisions AFTER INSERT OR DELETE ON inbox_decisions FOR EACH ROW EXECUTE FUNCTION workflow_mark_card()');
        $this->addSql('CREATE TRIGGER workflow_mark_inbox_decisions_update AFTER UPDATE ON inbox_decisions FOR EACH ROW WHEN ((to_jsonb(OLD) - ARRAY[]::text[]) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY[]::text[])) EXECUTE FUNCTION workflow_mark_card()');

        $source = Yaml::parseFile(__DIR__.'/../config/workflows/lifecycle.yaml');
        if (!\is_array($source)) {
            throw new \LogicException('The shipped workflow template "lifecycle" is not a map.');
        }
        // The epic branch, the setting values and the approval rule belong to the project, so each copy keeps its own value, or keeps none.
        $this->addSql(
            <<<'SQL'
                UPDATE workflow_bindings
                SET template_version = :version,
                    definition = (CAST(:definition AS JSONB) - 'epicBranch' - 'settingValues' - 'approvalCoversConflictResolution')
                        || CASE WHEN definition->'epicBranch' IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('epicBranch', definition->'epicBranch') END
                        || CASE WHEN definition->'settingValues' IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('settingValues', definition->'settingValues') END
                        || CASE WHEN definition->'approvalCoversConflictResolution' IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('approvalCoversConflictResolution', definition->'approvalCoversConflictResolution') END
                WHERE template_key = :key
                SQL,
            ['version' => TemplateVersion::of($source), 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), 'key' => 'lifecycle'],
        );
    }

    /** The old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER workflow_mark_inbox_decisions_update ON inbox_decisions');
        $this->addSql('DROP TRIGGER workflow_mark_inbox_decisions ON inbox_decisions');
        $this->addSql('DROP TABLE inbox_decisions');
    }
}
