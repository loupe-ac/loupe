<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261101015534;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261101015534.php';

final class LifecycleBlockerHoldMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_gets_the_open_blocker_branch_and_keeps_its_epic_branch_and_approval_rule(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-blocker-hold');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $old = $this->row($connection, $projectId);
        $old['version'] = 33;
        $old['epicBranch'] = 'feature/epic-{number}';
        $old['approvalCoversConflictResolution'] = false;
        $old['rules'] = self::withoutTheBranch($old['rules']);
        $connection->executeStatement('UPDATE workflow_bindings SET definition = CAST(? AS JSONB), template_version = 33 WHERE project_id = ?', [json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);
        self::assertNotEquals($this->service(ShippedTemplates::class)->source('lifecycle')['rules'], $old['rules']);

        $migration = new Version20261101015534($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertEquals([...$shipped, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $this->row($connection, $projectId));
        self::assertSame($shipped['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$projectId]));
    }

    /**
     * @return list<mixed>
     */
    private static function withoutTheBranch(mixed $rules): array
    {
        self::assertIsArray($rules);
        $stripped = [];
        $found = false;
        foreach ($rules as $rule) {
            if (\is_array($rule) && 'tech-design-approved' === ($rule['id'] ?? null)) {
                $all = $rule['when']['all'] ?? null;
                self::assertIsArray($all);
                foreach ($all as $index => $condition) {
                    if (\is_array($condition) && \is_array($condition['any'] ?? null)) {
                        $any = $condition['any'];
                        $all[$index]['any'] = array_values(array_filter($any, static fn (mixed $branch): bool => !\is_array($branch) || !\array_key_exists('card.blocker.open', $branch)));
                        self::assertCount(\count($any) - 1, $all[$index]['any']);
                        $found = true;
                    }
                }
                $rule['when']['all'] = $all;
            }
            $stripped[] = $rule;
        }
        self::assertTrue($found);

        return $stripped;
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
