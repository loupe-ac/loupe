<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261102011500;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261102011500.php';

final class LifecycleConflictChecksMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_gets_the_conflict_checks_and_keeps_its_epic_branch_and_approval_rule(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-conflict-checks');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $old = $this->definition($projectId);
        $old['version'] = 38;
        $old['epicBranch'] = 'feature/epic-{number}';
        $old['approvalCoversConflictResolution'] = false;
        self::assertIsArray($old['rules']);
        $old['rules'] = array_map(static function (mixed $rule): mixed {
            if (\is_array($rule) && \is_array($rule['then'] ?? null) && \is_array($rule['then']['forge-write'] ?? null)) {
                unset($rule['then']['forge-write']['checks']);
            }

            return $rule;
        }, $old['rules']);
        $this->em()->getConnection()->executeStatement(
            'UPDATE workflow_bindings SET definition = CAST(? AS JSONB), template_version = 38 WHERE project_id = ?',
            [json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId],
        );

        $this->migrate();

        $migrated = $this->definition($projectId);
        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertEquals([...$shipped, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $migrated);
        self::assertSame($shipped['version'], (int) $this->em()->getConnection()->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$projectId]));
    }

    private function migrate(): void
    {
        $connection = $this->em()->getConnection();
        $migration = new Version20261102011500($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return array<string, mixed> */
    private function definition(string $projectId): array
    {
        $definition = json_decode((string) $this->em()->getConnection()->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$projectId]), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($definition);

        /* @var array<string, mixed> $definition */
        return $definition;
    }
}
