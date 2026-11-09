<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateVersion;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261109195542;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261109195542.php';

final class LoupeMergeGateMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_copy_loses_its_blocking_approval_gate_and_gains_the_setting_and_keeps_its_own_keys(): void
    {
        self::bootKernel();
        $connection = $this->em()->getConnection();
        $project = $this->workflowProject('migration-merge-gate');
        $this->bindLifecycle($project);
        $id = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection->executeStatement(
            "UPDATE workflow_bindings SET template_version = 'stale', definition = jsonb_set(definition #- '{settings,4}', '{repositorySettings,0,mark}', '\"blocking\"') || '{\"epicBranch\": \"feature/epic-{number}\"}'::jsonb WHERE project_id = ?",
            [$id],
        );

        $migration = new Version20261109195542($connection, new NullLogger());
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
        self::assertSame('warning', array_column($definition['repositorySettings'], 'mark', 'key')['approval-gate']);
        self::assertContains('required-checks', array_column($definition['settings'], 'key'));
        self::assertSame('feature/epic-{number}', $definition['epicBranch']);
        self::assertSame(TemplateVersion::of($this->service(ShippedTemplates::class)->source('lifecycle')), $row['template_version']);
    }
}
