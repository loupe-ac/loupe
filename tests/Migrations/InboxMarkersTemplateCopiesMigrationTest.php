<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261022013540;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261022013540.php';

final class InboxMarkersTemplateCopiesMigrationTest extends KernelTestCase
{
    use ActionScenario;

    #[TestWith(['lifecycle'])]
    #[TestWith(['simple'])]
    public function test_a_binding_gets_the_shipped_copy_and_keeps_its_project_settings_and_the_other_template_stays_unchanged(string $key): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-inbox-markers-'.$key);
        $this->bind($project, $key);
        $otherKey = 'lifecycle' === $key ? 'simple' : 'lifecycle';
        $other = $this->workflowProject('migration-inbox-markers-other-'.$key);
        $this->bind($other, $otherKey);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $otherId = ($other->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();

        $old = $this->row($connection, $projectId);
        unset($old['inbox'], $old['facts']);
        $old['markers'] = [];
        $old['version'] = 1;
        $old['epicBranch'] = 'feature/epic-{number}';
        $old['approvalCoversConflictResolution'] = false;
        $connection->executeStatement('UPDATE workflow_bindings SET template_version = 1, definition = CAST(? AS JSONB) WHERE project_id = ?', [json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);
        $otherBefore = $this->binding($connection, $otherId);

        $this->migrate($connection);

        $source = $this->service(ShippedTemplates::class)->source($key);
        self::assertEquals([...$source, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $this->row($connection, $projectId));
        self::assertSame($source['version'], $this->binding($connection, $projectId)['template_version']);
        self::assertNotEmpty($this->row($connection, $projectId)['inbox']);
        $migrated = $this->binding($connection, $projectId);
        $otherAfter = $this->binding($connection, $otherId);
        self::assertSame($otherBefore['template_key'], $otherAfter['template_key']);

        $this->migrate($connection);

        self::assertSame($migrated, $this->binding($connection, $projectId));
    }

    private function bind(Project $project, string $key): void
    {
        if ('lifecycle' === $key) {
            $this->bindLifecycle($project);

            return;
        }
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, $key, []));
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261022013540($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return array<string, mixed> */
    private function binding(Connection $connection, string $projectId): array
    {
        $binding = $connection->fetchAssociative('SELECT template_key, template_version, definition FROM workflow_bindings WHERE project_id = ?', [$projectId]);
        self::assertIsArray($binding);

        return $binding;
    }

    /** @return array<string, mixed> */
    private function row(Connection $connection, string $projectId): array
    {
        $definition = $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$projectId]);
        self::assertIsString($definition);
        $decoded = json_decode($definition, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
