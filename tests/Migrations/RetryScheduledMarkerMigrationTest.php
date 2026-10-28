<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261028184800;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261028184800.php';

final class RetryScheduledMarkerMigrationTest extends KernelTestCase
{
    use ActionScenario;

    private const array RETRY = ['card.rule.retry_scheduled' => []];

    #[TestWith(['lifecycle', 'simple'])]
    #[TestWith(['simple', 'lifecycle'])]
    public function test_a_binding_gets_the_retry_marker_and_the_stuck_markers_that_exclude_it_and_keeps_its_project_settings(string $key, string $otherKey): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-retry-marker-'.$key);
        $this->bind($project, $key);
        $other = $this->workflowProject('migration-retry-marker-other-'.$key);
        $this->bind($other, $otherKey);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $otherId = ($other->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();

        $old = $this->row($connection, $projectId);
        $old['version'] = $old['version'] - 1;
        $old['epicBranch'] = 'feature/epic-{number}';
        $old['approvalCoversConflictResolution'] = false;
        $old['markers'] = array_values(array_filter($old['markers'], static fn (array $marker): bool => 'retry-scheduled' !== $marker['key']));
        foreach ($old['markers'] as $i => $marker) {
            if (\in_array($marker['key'], ['run-stopped', 'checks-failed'], true)) {
                $last = array_key_last($marker['when']['all']);
                $old['markers'][$i]['when']['all'][$last]['not']['any'] = array_values(array_filter($marker['when']['all'][$last]['not']['any'], static fn (array $condition): bool => self::RETRY !== $condition));
            }
        }
        $connection->executeStatement('UPDATE workflow_bindings SET template_version = ?, definition = CAST(? AS JSONB) WHERE project_id = ?', [$old['version'], json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);
        self::assertNotContains('retry-scheduled', array_column($this->row($connection, $projectId)['markers'], 'key'));

        $this->migrate($connection);

        $source = $this->service(ShippedTemplates::class)->source($key);
        $migrated = $this->row($connection, $projectId);
        self::assertEquals([...$source, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $migrated);
        self::assertSame($source['version'], $this->binding($connection, $projectId)['template_version']);
        $markers = array_column($migrated['markers'], null, 'key');
        self::assertSame(self::RETRY, $markers['retry-scheduled']['when']);
        foreach (['run-stopped', 'checks-failed'] as $stuck) {
            $last = array_key_last($markers[$stuck]['when']['all']);
            self::assertContains(self::RETRY, $markers[$stuck]['when']['all'][$last]['not']['any'], $stuck);
        }
        self::assertSame($this->service(ShippedTemplates::class)->source($otherKey)['version'], $this->binding($connection, $otherId)['template_version']);
        $after = $this->binding($connection, $projectId);

        $this->migrate($connection);

        self::assertSame($after, $this->binding($connection, $projectId));
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
        $migration = new Version20261028184800($connection, new NullLogger());
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
