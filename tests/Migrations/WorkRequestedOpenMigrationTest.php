<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261021231057;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261021231057.php';

final class WorkRequestedOpenMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_and_a_simple_binding_show_the_marker_for_open_work_only_and_keep_their_project_settings(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-work-open-lifecycle');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-work-open-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->store($connection, $lifecycleId, $this->withActiveCondition($this->row($connection, $lifecycleId), 'feature/epic-{number}', false));
        $this->store($connection, $simpleId, $this->withActiveCondition($this->row($connection, $simpleId), null, true));
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);
        self::assertStringContainsString('card.run.work_active', json_encode($this->row($connection, $lifecycleId), \JSON_THROW_ON_ERROR));

        $this->migrate($connection);
        $this->migrate($connection);

        self::assertEquals([...$shipped->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $this->row($connection, $lifecycleId));
        self::assertEquals([...array_diff_key($shipped->source('simple'), ['epicBranch' => true]), 'approvalCoversConflictResolution' => true], $this->row($connection, $simpleId));
        self::assertSame(['card.run.work_open' => []], $this->workRequestedCondition($this->row($connection, $lifecycleId)));
        self::assertSame(['card.run.work_open' => []], $this->workRequestedCondition($this->row($connection, $simpleId)));
        self::assertSame($shipped->source('lifecycle')['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$lifecycleId]));
        self::assertSame($shipped->source('simple')['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$simpleId]));
    }

    public function test_a_binding_of_another_template_is_left_alone(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-work-open-other');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->store($connection, $projectId, $this->withActiveCondition($this->row($connection, $projectId), null, null));
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
    private function withActiveCondition(array $definition, ?string $epicBranch, ?bool $approvalCovers): array
    {
        $markers = $definition['markers'];
        self::assertIsArray($markers);
        $definition['markers'] = array_map(static function (mixed $marker): mixed {
            if (!\is_array($marker) || 'work-requested' !== $marker['key']) {
                return $marker;
            }
            $marker['when']['all'][0] = ['card.run.work_active' => []];

            return $marker;
        }, $markers);
        $definition['version'] = 1;
        unset($definition['epicBranch'], $definition['approvalCoversConflictResolution']);
        if (null !== $epicBranch) {
            $definition['epicBranch'] = $epicBranch;
        }
        if (null !== $approvalCovers) {
            $definition['approvalCoversConflictResolution'] = $approvalCovers;
        }

        return $definition;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function workRequestedCondition(array $definition): array
    {
        $markers = $definition['markers'];
        self::assertIsArray($markers);
        $marker = array_find($markers, static fn (mixed $marker): bool => \is_array($marker) && 'work-requested' === $marker['key']);
        self::assertIsArray($marker);

        return $marker['when']['all'][0];
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
        $migration = new Version20261021231057($connection, new NullLogger());
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
