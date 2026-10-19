<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261018223429;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261018223429.php';

final class ChildMarkersMigrationTest extends KernelTestCase
{
    use ActionScenario;

    private const array CHILD_MARKERS = ['child-stuck', 'child-needs-you', 'child-working', 'child-waiting'];

    public function test_a_lifecycle_and_a_simple_binding_get_the_child_markers_and_a_lifecycle_binding_keeps_its_epic_branch(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-child-markers-lifecycle');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-child-markers-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->store($connection, $lifecycleId, $this->withoutChildMarkers($this->row($connection, $lifecycleId), 'feature/epic-{number}'));
        $this->store($connection, $simpleId, $this->withoutChildMarkers($this->row($connection, $simpleId), null));
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);

        $this->migrate($connection);
        $this->migrate($connection);

        self::assertEquals([...$shipped->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}'], $this->row($connection, $lifecycleId));
        self::assertEquals(array_diff_key($shipped->source('simple'), ['epicBranch' => true]), $this->row($connection, $simpleId));
        foreach ([$lifecycleId, $simpleId] as $projectId) {
            self::assertSame(self::CHILD_MARKERS, array_values(array_intersect(self::keys($this->row($connection, $projectId)), self::CHILD_MARKERS)));
        }
        self::assertSame($shipped->source('lifecycle')['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$lifecycleId]));
        self::assertSame($shipped->source('simple')['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$simpleId]));
    }

    public function test_a_binding_of_another_template_is_left_alone(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-child-markers-other');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->store($connection, $projectId, $this->withoutChildMarkers($this->row($connection, $projectId), null));
        $connection->executeStatement("UPDATE workflow_bindings SET template_key = 'custom' WHERE project_id = ?", [$projectId]);
        $before = $this->row($connection, $projectId);

        $this->migrate($connection);

        self::assertEquals($before, $this->row($connection, $projectId));
        self::assertSame([], array_intersect(self::keys($before), self::CHILD_MARKERS));
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function withoutChildMarkers(array $definition, ?string $epicBranch): array
    {
        $markers = $definition['markers'];
        self::assertIsArray($markers);
        $definition['markers'] = array_values(array_filter($markers, static fn (mixed $marker): bool => !\is_array($marker) || !\in_array($marker['key'], self::CHILD_MARKERS, true)));
        $definition['version'] = 5;
        unset($definition['epicBranch']);
        if (null !== $epicBranch) {
            $definition['epicBranch'] = $epicBranch;
        }

        return $definition;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    private static function keys(array $definition): array
    {
        $markers = $definition['markers'];
        self::assertIsArray($markers);

        return array_values(array_map(static fn (mixed $marker): string => \is_array($marker) && \is_string($marker['key'] ?? null) ? $marker['key'] : '', $markers));
    }

    /** @param array<string, mixed> $definition */
    private function store(Connection $connection, string $projectId, array $definition): void
    {
        $connection->executeStatement(
            'UPDATE workflow_bindings SET template_version = 5, definition = CAST(? AS JSONB) WHERE project_id = ?',
            [json_encode($definition, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId],
        );
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261018223429($connection, new NullLogger());
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
