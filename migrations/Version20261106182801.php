<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Module\Workflow\Template\TemplateVersion;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261106182801 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the setup failure fingerprint to the workflow rule states, and write the shipped Lifecycle copy, with repeatGoesToRepair, into every Lifecycle binding';
    }

    /**
     * The epic branch and the approval rule belong to the project, so each copy keeps its own value, or keeps none.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states ADD last_setup_failure VARCHAR(64) DEFAULT NULL');

        $source = Yaml::parseFile(__DIR__.'/../config/workflows/lifecycle.yaml');
        if (!\is_array($source)) {
            throw new \LogicException('The shipped workflow template "lifecycle" is not a map.');
        }
        $this->addSql(
            <<<'SQL'
                UPDATE workflow_bindings
                SET template_version = :version,
                    definition = (CAST(:definition AS JSONB) - 'epicBranch' - 'approvalCoversConflictResolution')
                        || CASE WHEN definition->'epicBranch' IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('epicBranch', definition->'epicBranch') END
                        || CASE WHEN definition->'approvalCoversConflictResolution' IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('approvalCoversConflictResolution', definition->'approvalCoversConflictResolution') END
                WHERE template_key = 'lifecycle'
                SQL,
            ['version' => TemplateVersion::of($source), 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)],
        );
    }

    /** The old copies are not kept, so only the column goes. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states DROP last_setup_failure');
    }
}
