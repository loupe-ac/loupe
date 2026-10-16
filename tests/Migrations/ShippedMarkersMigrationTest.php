<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261013004300;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261013004300.php';

final class ShippedMarkersMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_gets_the_markers_and_keeps_its_epic_branch(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-markers-lifecycle');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE workflow_bindings SET template_version = 1, definition = jsonb_set(jsonb_set(definition, '{epicBranch}', '\"feature/epic-{number}\"'), '{version}', '1') - 'markers' WHERE project_id = ?", [$projectId]);

        $this->migrate($connection);

        $row = $this->row($connection, $projectId);
        self::assertEquals([...$this->service(ShippedTemplates::class)->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}'], $row['definition']);
        self::assertSame(3, $row['version']);
        self::assertNotEmpty($row['definition']['markers']);
    }

    public function test_a_simple_binding_gets_the_markers(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-markers-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE workflow_bindings SET template_version = 1, definition = definition - 'markers' WHERE project_id = ?", [$projectId]);

        $this->migrate($connection);

        $row = $this->row($connection, $projectId);
        self::assertEquals($this->service(ShippedTemplates::class)->source('simple'), $row['definition']);
        self::assertSame(2, $row['version']);
        self::assertNotEmpty($row['definition']['markers']);
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261013004300($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return array{definition: array<string, mixed>, version: int} */
    private function row(Connection $connection, string $projectId): array
    {
        $row = $connection->fetchAssociative('SELECT template_version, definition FROM workflow_bindings WHERE project_id = ?', [$projectId]);
        self::assertIsArray($row);
        self::assertIsString($row['definition']);

        return ['definition' => json_decode($row['definition'], true, flags: \JSON_THROW_ON_ERROR), 'version' => (int) $row['template_version']];
    }
}
