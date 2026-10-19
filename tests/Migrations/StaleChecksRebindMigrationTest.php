<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261019115609;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261019115609.php';

final class StaleChecksRebindMigrationTest extends KernelTestCase
{
    use ActionScenario;

    private const string PUSH_CHECK = 'The agent pushes as its own GitHub account';
    private const string TEARDOWN_CHECK = 'A command that removes the worktree and the preview of a finished card';

    public function test_a_lifecycle_and_a_simple_binding_lose_the_stale_checks_and_a_lifecycle_binding_keeps_its_epic_branch_and_approval_rule(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-stale-checks-lifecycle');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-stale-checks-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->store($connection, $lifecycleId, ['approvalCoversConflictResolution' => false] + $this->withStaleChecks($this->row($connection, $lifecycleId), 'feature/epic-{number}', 9));
        $this->store($connection, $simpleId, $this->withStaleChecks($this->row($connection, $simpleId), null, 7));
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);
        self::assertContains(self::PUSH_CHECK, $this->checks($this->row($connection, $lifecycleId)));

        $this->migrate($connection);
        $this->migrate($connection);

        self::assertEquals([...$shipped->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $this->row($connection, $lifecycleId));
        self::assertEquals(array_diff_key($shipped->source('simple'), ['epicBranch' => true, 'approvalCoversConflictResolution' => true]), $this->row($connection, $simpleId));
        self::assertNotContains(self::PUSH_CHECK, $this->checks($this->row($connection, $lifecycleId)));
        self::assertNotContains(self::TEARDOWN_CHECK, $this->checks($this->row($connection, $lifecycleId)));
        self::assertNotContains(self::TEARDOWN_CHECK, $this->checks($this->row($connection, $simpleId)));
        self::assertSame($shipped->source('lifecycle')['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$lifecycleId]));
        self::assertSame($shipped->source('simple')['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$simpleId]));
    }

    public function test_a_binding_of_another_template_is_left_alone(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-stale-checks-other');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->store($connection, $projectId, $this->withStaleChecks($this->row($connection, $projectId), null, 9));
        $connection->executeStatement("UPDATE workflow_bindings SET template_key = 'custom' WHERE project_id = ?", [$projectId]);
        $before = $this->row($connection, $projectId);

        $this->migrate($connection);

        self::assertEquals($before, $this->row($connection, $projectId));
        self::assertContains(self::PUSH_CHECK, $this->checks($before));
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function withStaleChecks(array $definition, ?string $epicBranch, int $version): array
    {
        $definition['version'] = $version;
        unset($definition['epicBranch']);
        if (null !== $epicBranch) {
            $definition['epicBranch'] = $epicBranch;
        }
        $definition['rules'] = array_map(static function (mixed $rule): mixed {
            if (!\is_array($rule)) {
                return $rule;
            }
            if ('implement' === ($rule['id'] ?? null)) {
                $rule['then']['request']['checks'][] = self::PUSH_CHECK;
            }
            if ('teardown' === ($rule['id'] ?? null)) {
                $rule['then']['request']['checks'] = [self::TEARDOWN_CHECK];
            }

            return $rule;
        }, $definition['rules']);

        return $definition;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return list<string> every check of every request rule
     */
    private function checks(array $definition): array
    {
        $checks = [];
        foreach ($definition['rules'] as $rule) {
            foreach ($rule['then']['request']['checks'] ?? [] as $check) {
                $checks[] = $check;
            }
        }

        return $checks;
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
        $migration = new Version20261019115609($connection, new NullLogger());
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
