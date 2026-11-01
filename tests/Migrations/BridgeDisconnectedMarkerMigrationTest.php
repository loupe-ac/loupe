<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261031155928;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261031155928.php';

final class BridgeDisconnectedMarkerMigrationTest extends KernelTestCase
{
    use ActionScenario;

    #[TestWith(['lifecycle'])]
    #[TestWith(['simple'])]
    public function test_a_binding_gets_the_bridge_disconnected_marker_and_keeps_its_project_settings(string $key): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-bridge-disconnected-'.$key);
        $this->bind($project, $key);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();

        $old = $this->row($connection, $projectId);
        $old['version'] = $old['version'] - 1;
        $old['epicBranch'] = 'feature/epic-{number}';
        $old['approvalCoversConflictResolution'] = false;
        $old['markers'] = array_values(array_filter($old['markers'], static fn (array $marker): bool => 'bridge-disconnected' !== $marker['key']));
        $connection->executeStatement('UPDATE workflow_bindings SET template_version = ?, definition = CAST(? AS JSONB) WHERE project_id = ?', [$old['version'], json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);
        self::assertNotContains('bridge-disconnected', array_column($this->row($connection, $projectId)['markers'], 'key'));

        $this->migrate($connection);

        $source = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $source);
        $migrated = $this->row($connection, $projectId);
        self::assertEquals([...$source->source($key), 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $migrated);
        self::assertContains('bridge-disconnected', array_column($migrated['markers'], 'key'));
        self::assertSame($source->source($key)['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$projectId]));
        $after = $this->row($connection, $projectId);

        $this->migrate($connection);

        self::assertSame($after, $this->row($connection, $projectId));
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
        $migration = new Version20261031155928($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
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
