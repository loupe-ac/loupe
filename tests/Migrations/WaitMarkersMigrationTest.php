<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261124090000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261124090000.php';

final class WaitMarkersMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_and_a_simple_binding_get_the_shipped_copy_with_the_waiting_marker_and_the_lifecycle_binding_keeps_its_epic_branch(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-wait-lifecycle');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-wait-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->store($connection, $lifecycleId, $this->withoutTheMarker($this->row($connection, $lifecycleId), 'feature/epic-{number}'));
        $this->store($connection, $simpleId, $this->withoutTheMarker($this->row($connection, $simpleId), null));
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);

        $this->migrate($connection);

        self::assertEquals([...$shipped->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}'], $this->row($connection, $lifecycleId));
        self::assertEquals(array_diff_key($shipped->source('simple'), ['epicBranch' => true]), $this->row($connection, $simpleId));
        self::assertContains('worker-waiting', array_column($this->row($connection, $simpleId)['markers'], 'key'));
    }

    public function test_a_binding_of_another_template_is_left_alone(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-wait-other');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE workflow_bindings SET template_key = 'custom' WHERE project_id = ?", [$projectId]);
        $before = $this->row($connection, $projectId);

        $this->migrate($connection);

        self::assertEquals($before, $this->row($connection, $projectId));
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function withoutTheMarker(array $definition, ?string $epicBranch): array
    {
        $markers = $definition['markers'];
        self::assertIsArray($markers);
        $definition['markers'] = array_values(array_filter($markers, static fn (mixed $marker): bool => \is_array($marker) && 'worker-waiting' !== $marker['key']));
        $definition['version'] = 2;
        unset($definition['epicBranch']);
        if (null !== $epicBranch) {
            $definition['epicBranch'] = $epicBranch;
        }

        return $definition;
    }

    /** @param array<string, mixed> $definition */
    private function store(Connection $connection, string $projectId, array $definition): void
    {
        $connection->executeStatement(
            'UPDATE workflow_bindings SET template_version = 2, definition = CAST(? AS JSONB) WHERE project_id = ?',
            [json_encode($definition, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId],
        );
    }

    /** Runs the data statements only: the schema statements of the migration ran when the database was built. */
    private function migrate(Connection $connection): void
    {
        $migration = new Version20261124090000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            if (str_starts_with($query->getStatement(), 'UPDATE')) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
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
