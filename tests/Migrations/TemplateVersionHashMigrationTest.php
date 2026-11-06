<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateVersion;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261106170000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261106170000.php';

final class TemplateVersionHashMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_binding_gets_the_hash_of_its_stored_copy(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-version-hash');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement('UPDATE workflow_bindings SET template_version = ? WHERE project_id = ?', ['14', $projectId]);

        $migration = new Version20261106170000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $migration->postUp(new Schema());

        $source = $this->service(ShippedTemplates::class)->source('simple');
        self::assertSame(TemplateVersion::of($source), $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$projectId]));
    }
}
