<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261018213758;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261018213758.php';

final class LifecycleSlotTonesMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_gets_the_slot_tones_and_keeps_its_epic_branch_and_a_simple_binding_is_left_alone(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-slot-tones-lifecycle');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-slot-tones-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $old = $this->row($connection, $lifecycleId);
        $old['version'] = 7;
        $old['epicBranch'] = 'feature/epic-{number}';
        $old['slots'] = array_map(static fn (mixed $slot): mixed => \is_array($slot) ? array_diff_key($slot, ['tone' => true]) : $slot, $old['slots']);
        $connection->executeStatement('UPDATE workflow_bindings SET template_version = 7, definition = CAST(? AS JSONB) WHERE project_id = ?', [json_encode($old, \JSON_THROW_ON_ERROR), $lifecycleId]);
        $simpleBefore = $this->row($connection, $simpleId);
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);

        $this->migrate($connection);
        $this->migrate($connection);

        self::assertEquals([...$shipped->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}'], $this->row($connection, $lifecycleId));
        self::assertSame($shipped->source('lifecycle')['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$lifecycleId]));
        self::assertSame(['lime', 'sky', 'indigo', 'purple', 'amber'], array_column($this->row($connection, $lifecycleId)['slots'], 'tone'));
        self::assertEquals($simpleBefore, $this->row($connection, $simpleId));
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261018213758($connection, new NullLogger());
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
