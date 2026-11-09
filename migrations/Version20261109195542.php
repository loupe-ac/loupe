<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Module\Workflow\Template\TemplateVersion;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261109195542 extends AbstractMigration
{
    private const array TEMPLATES = ['lifecycle', 'simple'];

    #[\Override]
    public function getDescription(): string
    {
        return 'Store the required checks that a head has not reported, and write the shipped template copies, with a suggestion instead of a blocking approval gate and the required-checks setting, into every binding';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD missing_checks JSON DEFAULT \'[]\' NOT NULL');

        foreach (self::TEMPLATES as $template) {
            $source = Yaml::parseFile(__DIR__.'/../config/workflows/'.$template.'.yaml');
            if (!\is_array($source)) {
                throw new \LogicException(\sprintf('The shipped workflow template "%s" is not a map.', $template));
            }
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
                ['version' => TemplateVersion::of($source), 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), 'key' => $template],
            );
        }
    }

    /** The old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP missing_checks');
    }
}
