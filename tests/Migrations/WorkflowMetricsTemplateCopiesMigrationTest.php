<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261028001522;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261028001522.php';

final class WorkflowMetricsTemplateCopiesMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_binding_with_an_old_copy_gets_the_shipped_copy_with_its_metrics(): void
    {
        self::bootKernel();
        $connection = $this->em()->getConnection();
        $shipped = $this->service(ShippedTemplates::class);
        $ids = [];
        foreach (['lifecycle', 'simple'] as $key) {
            $project = $this->workflowProject('migration-metrics-'.$key);
            'lifecycle' === $key ? $this->bindLifecycle($project) : $this->bindHandler()(new BindWorkflowTemplateCommand($project, $key, []));
            $ids[$key] = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
            $connection->executeStatement("UPDATE workflow_bindings SET template_version = 0, definition = definition - 'metrics' - 'outcomes' WHERE project_id = ?", [$ids[$key]]);
        }

        $migration = new Version20261028001522($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        foreach ($ids as $key => $projectId) {
            $row = $connection->fetchAssociative('SELECT template_version, definition FROM workflow_bindings WHERE project_id = ?', [$projectId]);
            self::assertIsArray($row);
            self::assertIsString($row['definition']);
            $source = $shipped->source($key);
            self::assertEquals($source, json_decode($row['definition'], true));
            self::assertSame($source['version'] ?? null, (int) $row['template_version']);
            self::assertNotEmpty($source['metrics'] ?? null);
        }
    }
}
