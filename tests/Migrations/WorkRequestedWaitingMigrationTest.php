<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261018184213;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261018184213.php';

final class WorkRequestedWaitingMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_and_a_simple_binding_show_requested_work_as_waiting_and_a_lifecycle_binding_keeps_its_epic_branch(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-work-requested-waiting-lifecycle');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-work-requested-waiting-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->store($connection, $lifecycleId, $this->withWorkingRequest($this->row($connection, $lifecycleId), 'feature/epic-{number}', 6));
        $this->store($connection, $simpleId, $this->withWorkingRequest($this->row($connection, $simpleId), null, 5));
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);

        $this->migrate($connection);
        $this->migrate($connection);

        self::assertEquals([...$shipped->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}'], $this->row($connection, $lifecycleId));
        self::assertEquals(array_diff_key($shipped->source('simple'), ['epicBranch' => true]), $this->row($connection, $simpleId));
        self::assertSame('waiting', $this->workRequestedKind($this->row($connection, $lifecycleId)));
        self::assertSame('waiting', $this->workRequestedKind($this->row($connection, $simpleId)));
        self::assertSame($shipped->source('lifecycle')['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$lifecycleId]));
        self::assertSame($shipped->source('simple')['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$simpleId]));
    }

    public function test_a_binding_of_another_template_is_left_alone(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-work-requested-waiting-other');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->store($connection, $projectId, $this->withWorkingRequest($this->row($connection, $projectId), null, 6));
        $connection->executeStatement("UPDATE workflow_bindings SET template_key = 'custom' WHERE project_id = ?", [$projectId]);
        $before = $this->row($connection, $projectId);

        $this->migrate($connection);

        self::assertEquals($before, $this->row($connection, $projectId));
        self::assertSame('working', $this->workRequestedKind($before));
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function withWorkingRequest(array $definition, ?string $epicBranch, int $version): array
    {
        $markers = $definition['markers'];
        self::assertIsArray($markers);
        $definition['markers'] = array_map(static fn (mixed $marker): mixed => \is_array($marker) && 'work-requested' === $marker['key'] ? ['kind' => 'working'] + $marker : $marker, $markers);
        $definition['version'] = $version;
        unset($definition['epicBranch']);
        if (null !== $epicBranch) {
            $definition['epicBranch'] = $epicBranch;
        }

        return $definition;
    }

    /** @param array<string, mixed> $definition */
    private function workRequestedKind(array $definition): mixed
    {
        $markers = $definition['markers'];
        self::assertIsArray($markers);
        $marker = array_find($markers, static fn (mixed $marker): bool => \is_array($marker) && 'work-requested' === $marker['key']);
        self::assertIsArray($marker);

        return $marker['kind'];
    }

    /** @param array<string, mixed> $definition */
    private function store(Connection $connection, string $projectId, array $definition): void
    {
        $connection->executeStatement(
            'UPDATE workflow_bindings SET template_version = ?, definition = CAST(? AS JSONB) WHERE project_id = ?',
            [$definition['version'], json_encode($definition, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId],
        );
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261018184213($connection, new NullLogger());
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
