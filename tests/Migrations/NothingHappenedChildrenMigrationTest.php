<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261020142320;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261020142320.php';

final class NothingHappenedChildrenMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_counts_the_children_in_its_nothing_happened_marker_and_keeps_its_epic_branch(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-quiet-children-lifecycle');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->store($connection, $projectId, $this->withoutChildren($this->row($connection, $projectId), 'feature/epic-{number}'));
        self::assertSame([false], $this->childrenOf($this->row($connection, $projectId)));
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);

        $this->migrate($connection);
        $this->migrate($connection);

        self::assertEquals([...$shipped->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}'], $this->row($connection, $projectId));
        self::assertSame([true], $this->childrenOf($this->row($connection, $projectId)));
        self::assertSame($shipped->source('lifecycle')['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$projectId]));
    }

    public function test_a_simple_binding_is_left_alone(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-quiet-children-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $before = $this->row($connection, $projectId);
        $versionBefore = $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$projectId]);

        $this->migrate($connection);

        self::assertEquals($before, $this->row($connection, $projectId));
        self::assertSame($versionBefore, $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$projectId]));
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function withoutChildren(array $definition, ?string $epicBranch): array
    {
        $json = json_encode($definition, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        $decoded = json_decode(str_replace('{"children":true}', '{}', $json), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $decoded['version'] = 11;
        unset($decoded['epicBranch']);
        if (null !== $epicBranch) {
            $decoded['epicBranch'] = $epicBranch;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return list<bool>
     */
    private function childrenOf(array $definition): array
    {
        $marker = array_find($definition['markers'], static fn (array $marker): bool => 'nothing-happened' === $marker['key']);
        self::assertIsArray($marker);
        $quiet = array_find($marker['when']['all'], static fn (array $leaf): bool => isset($leaf['card.quiet_for']));
        self::assertIsArray($quiet);

        return [true === ($quiet['card.quiet_for']['children'] ?? false)];
    }

    /** @param array<string, mixed> $definition */
    private function store(Connection $connection, string $projectId, array $definition): void
    {
        $connection->executeStatement(
            'UPDATE workflow_bindings SET template_version = 11, definition = CAST(? AS JSONB) WHERE project_id = ?',
            [json_encode($definition, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId],
        );
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261020142320($connection, new NullLogger());
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
