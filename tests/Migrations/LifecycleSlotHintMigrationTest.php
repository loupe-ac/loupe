<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateVersion;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261107140000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261107140000.php';

final class LifecycleSlotHintMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_copy_gets_the_hints_and_keeps_its_two_project_keys(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-slot-hint');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement(
            "UPDATE workflow_bindings
            SET template_version = 'old',
                definition = jsonb_set(jsonb_set(definition, '{epicBranch}', '\"feature/epic-{number}\"'), '{approvalCoversConflictResolution}', 'false')
            WHERE project_id = ?",
            [$projectId],
        );

        $migration = new Version20261107140000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        $stored = $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$projectId]);
        self::assertIsString($stored);
        $after = json_decode($stored, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($after);
        self::assertSame(TemplateVersion::of($shipped), $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$projectId]));
        self::assertSame(['workflow.slot_hint.next', 'workflow.slot_hint.product_design', 'workflow.slot_hint.tech_design'], array_values(array_filter(array_column($after['slots'], 'hint'))));
        self::assertSame('feature/epic-{number}', $after['epicBranch']);
        self::assertFalse($after['approvalCoversConflictResolution']);
    }
}
