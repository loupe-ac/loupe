<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261017140731;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261017140731.php';

final class StopMarkersGiveWayToQuestionMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_and_a_simple_binding_get_the_stop_markers_that_give_way_to_a_question_and_a_lifecycle_binding_keeps_its_epic_branch(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-stop-markers-lifecycle');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-stop-markers-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->store($connection, $lifecycleId, $this->withPlainStopMarkers($this->row($connection, $lifecycleId), 'feature/epic-{number}'));
        $this->store($connection, $simpleId, $this->withPlainStopMarkers($this->row($connection, $simpleId), null));
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);

        $this->migrate($connection);
        $this->migrate($connection);

        self::assertEquals([...$shipped->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}'], $this->row($connection, $lifecycleId));
        self::assertEquals(array_diff_key($shipped->source('simple'), ['epicBranch' => true]), $this->row($connection, $simpleId));
        foreach ([$lifecycleId, $simpleId] as $projectId) {
            self::assertStringContainsString('card.inbox.open_question', json_encode($this->marker($this->row($connection, $projectId), 'paused'), \JSON_THROW_ON_ERROR));
            self::assertStringContainsString('card.inbox.open_question', json_encode($this->marker($this->row($connection, $projectId), 'run-stopped'), \JSON_THROW_ON_ERROR));
        }
        self::assertSame($shipped->source('lifecycle')['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$lifecycleId]));
        self::assertSame($shipped->source('simple')['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$simpleId]));
    }

    public function test_a_binding_of_another_template_is_left_alone(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-stop-markers-other');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->store($connection, $projectId, $this->withPlainStopMarkers($this->row($connection, $projectId), null));
        $connection->executeStatement("UPDATE workflow_bindings SET template_key = 'custom' WHERE project_id = ?", [$projectId]);
        $before = $this->row($connection, $projectId);

        $this->migrate($connection);

        self::assertEquals($before, $this->row($connection, $projectId));
        self::assertStringNotContainsString('card.inbox.open_question', json_encode($this->marker($before, 'paused'), \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function withPlainStopMarkers(array $definition, ?string $epicBranch): array
    {
        $markers = $definition['markers'];
        self::assertIsArray($markers);
        $definition['markers'] = array_map(static function (mixed $marker): mixed {
            if (!\is_array($marker)) {
                return $marker;
            }

            return match ($marker['key']) {
                'paused' => ['when' => ['card.paused' => []]] + $marker,
                'run-stopped' => ['when' => ['card.run.stopped' => []]] + $marker,
                default => $marker,
            };
        }, $markers);
        $definition['version'] = 4;
        unset($definition['epicBranch']);
        if (null !== $epicBranch) {
            $definition['epicBranch'] = $epicBranch;
        }

        return $definition;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<mixed>
     */
    private function marker(array $definition, string $key): array
    {
        $markers = $definition['markers'];
        self::assertIsArray($markers);
        $marker = array_find($markers, static fn (mixed $marker): bool => \is_array($marker) && $key === $marker['key']);
        self::assertIsArray($marker);

        return $marker;
    }

    /** @param array<string, mixed> $definition */
    private function store(Connection $connection, string $projectId, array $definition): void
    {
        $connection->executeStatement(
            'UPDATE workflow_bindings SET template_version = 4, definition = CAST(? AS JSONB) WHERE project_id = ?',
            [json_encode($definition, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId],
        );
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261017140731($connection, new NullLogger());
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
