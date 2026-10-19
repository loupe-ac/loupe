<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261018223500;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261018223500.php';

final class FixRulesWaitForAnswerMigrationTest extends KernelTestCase
{
    use ActionScenario;

    private const string CONDITION = 'card.run.waits_on_answer';

    public function test_a_lifecycle_binding_gets_the_fix_rules_that_wait_and_keeps_its_epic_branch(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-fix-rules-wait-lifecycle');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->store($connection, $projectId, $this->withoutTheHold($this->row($connection, $projectId), 'feature/epic-{number}'));
        self::assertSame(0, $this->holdsIn($this->row($connection, $projectId)));
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);

        $this->migrate($connection);
        $this->migrate($connection);

        self::assertEquals([...$shipped->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}'], $this->row($connection, $projectId));
        self::assertSame(4, $this->holdsIn($this->row($connection, $projectId)));
        self::assertSame($shipped->source('lifecycle')['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$projectId]));
    }

    public function test_a_simple_binding_is_left_alone(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-fix-rules-wait-simple');
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
    private function withoutTheHold(array $definition, ?string $epicBranch): array
    {
        $json = json_encode($definition, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        $decoded = json_decode(str_replace('"'.self::CONDITION.'"', '"card.paused"', $json), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $decoded['version'] = 6;
        unset($decoded['epicBranch']);
        if (null !== $epicBranch) {
            $decoded['epicBranch'] = $epicBranch;
        }

        return $decoded;
    }

    /** @param array<string, mixed> $definition */
    private function holdsIn(array $definition): int
    {
        return substr_count(json_encode($definition, \JSON_THROW_ON_ERROR), '"'.self::CONDITION.'"');
    }

    /** @param array<string, mixed> $definition */
    private function store(Connection $connection, string $projectId, array $definition): void
    {
        $connection->executeStatement(
            'UPDATE workflow_bindings SET template_version = 6, definition = CAST(? AS JSONB) WHERE project_id = ?',
            [json_encode($definition, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId],
        );
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261018223500($connection, new NullLogger());
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
