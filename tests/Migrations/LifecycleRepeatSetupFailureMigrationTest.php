<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateVersion;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261106182801;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261106182801.php';

final class LifecycleRepeatSetupFailureMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_copy_gets_the_repeat_option_and_the_hash_and_keeps_its_epic_branch(): void
    {
        self::bootKernel();
        $connection = $this->em()->getConnection();
        $project = $this->workflowProject('migration-repeat-setup');
        $this->bindLifecycle($project);
        $id = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection->executeStatement("UPDATE workflow_bindings SET definition = (definition #- '{onWorkFailed,repeatGoesToRepair}') || '{\"epicBranch\": \"feature/epic-{number}\"}'::jsonb, template_version = 'stale' WHERE project_id = ?", [$id]);

        $migration = new Version20261106182801($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            if (!str_starts_with($query->getStatement(), 'ALTER TABLE')) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }

        $row = $connection->fetchAssociative('SELECT definition, template_version FROM workflow_bindings WHERE project_id = ?', [$id]);
        self::assertIsArray($row);
        $definition = json_decode((string) $row['definition'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($definition);
        self::assertTrue($definition['onWorkFailed']['repeatGoesToRepair']);
        self::assertSame('feature/epic-{number}', $definition['epicBranch']);
        self::assertSame(TemplateVersion::of($this->service(ShippedTemplates::class)->source('lifecycle')), $row['template_version']);
    }
}
