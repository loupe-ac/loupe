<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Contract\AskMode;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplateVersion;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261123223500;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261123223500.php';

final class AskLabelsTemplateMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_gets_the_ask_labels_and_keeps_its_project_values(): void
    {
        self::bootKernel();
        $connection = $this->em()->getConnection();
        $project = $this->workflowProject('migration-ask-labels');
        $this->bindLifecycle($project);
        $id = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection->executeStatement(
            <<<'SQL'
                UPDATE workflow_bindings
                SET template_version = 'stale',
                    definition = ((definition - 'asks') || '{"epicBranch": "feature/epic-{number}", "approvalCoversConflictResolution": false, "settingValues": {"required-checks": "all"}}'::jsonb)
                WHERE project_id = ?
                SQL,
            [$id],
        );

        $migration = new Version20261123223500($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        $row = $connection->fetchAssociative('SELECT definition, template_version FROM workflow_bindings WHERE project_id = ?', [$id]);
        self::assertIsArray($row);
        $definition = json_decode((string) $row['definition'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($definition);
        self::assertEquals([...$shipped, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false, 'settingValues' => ['required-checks' => 'all']], $definition);
        self::assertSame(TemplateVersion::of($shipped), $row['template_version']);
        $asks = $this->service(TemplateParser::class)->parseStored($definition)->asks;
        self::assertSame(AskMode::Log, $asks->modeOf('naming'));
        self::assertSame(AskMode::Default, $asks->modeOf('ordering'));
        self::assertSame(AskMode::Ask, $asks->modeOf('security'));
        self::assertSame(['undo'], $asks->fields);
    }
}
