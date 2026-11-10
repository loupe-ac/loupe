<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Entity\WorkflowRepositorySetting;
use App\Tests\Module\Workflow\WorkflowProjects;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261107052310;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

require_once __DIR__.'/../../migrations/Version20261107052310.php';

final class MergeApprovalBackfillMigrationTest extends KernelTestCase
{
    use WorkflowProjects;

    private int $sequence = 0;

    public function test_only_a_repository_of_an_active_installation_of_its_project_gets_the_answer(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('backfill-merge-approval');
        $other = $this->workflowProject('backfill-merge-approval-other');
        $removed = $this->workflowProject('backfill-merge-approval-removed');
        $suspended = $this->workflowProject('backfill-merge-approval-suspended');
        $active = $this->installation($project, 9001001);
        $this->installation($other, 9001002);
        $this->installation($removed, 9001003)->removedAt = new \DateTimeImmutable();
        $this->installation($suspended, 9001004)->suspendedAt = new \DateTimeImmutable();
        $claimed = $this->repository($project, ForgeRepositorySource::Installation, (string) $active->installationId);
        $hook = $this->repository($project, ForgeRepositorySource::Hook, (string) $active->installationId);
        $foreign = $this->repository($project, ForgeRepositorySource::Installation, '9001002');
        $gone = $this->repository($removed, ForgeRepositorySource::Installation, '9001003');
        $paused = $this->repository($suspended, ForgeRepositorySource::Installation, '9001004');
        $unreferenced = $this->repository($project, ForgeRepositorySource::Installation, null);
        $this->em()->flush();
        $connection = $this->em()->getConnection();

        $this->migrate($connection);

        self::assertSame([$claimed], $this->repositoriesWithAnswer($connection, [$claimed, $hook, $foreign, $gone, $paused, $unreferenced]));
        self::assertSame(['"agent"'], $connection->fetchFirstColumn("SELECT value FROM workflow_repository_settings WHERE repository_id = ? AND setting_key = 'merge-approval'", [$claimed]));
        self::assertSame($project->requireId()->toRfc4122(), $connection->fetchOne('SELECT project_id FROM workflow_repository_settings WHERE repository_id = ?', [$claimed]));
    }

    public function test_a_second_run_changes_nothing_and_keeps_an_existing_answer(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('backfill-merge-approval-twice');
        $installation = $this->installation($project, 9002001);
        $claimed = $this->repository($project, ForgeRepositorySource::Installation, (string) $installation->installationId);
        $answered = $this->repository($project, ForgeRepositorySource::Installation, (string) $installation->installationId);
        $this->em()->persist(new WorkflowRepositorySetting($project, Uuid::fromString($answered), 'merge-approval', '"person"'));
        $this->em()->flush();
        $connection = $this->em()->getConnection();

        $this->migrate($connection);
        $first = $connection->fetchAllKeyValue("SELECT repository_id, id || value FROM workflow_repository_settings WHERE setting_key = 'merge-approval'");
        $this->migrate($connection);

        self::assertSame($first, $connection->fetchAllKeyValue("SELECT repository_id, id || value FROM workflow_repository_settings WHERE setting_key = 'merge-approval'"));
        self::assertCount(2, $first);
        self::assertSame('"person"', $connection->fetchOne("SELECT value FROM workflow_repository_settings WHERE repository_id = ? AND setting_key = 'merge-approval'", [$answered]));
        self::assertSame('"agent"', $connection->fetchOne("SELECT value FROM workflow_repository_settings WHERE repository_id = ? AND setting_key = 'merge-approval'", [$claimed]));
    }

    private function installation(Project $project, int $installationId): GitHubInstallation
    {
        $installation = new GitHubInstallation($installationId, 'acme', GitHubRepositorySelection::All);
        $this->em()->persist($installation);
        $this->em()->flush();
        // The migration under test predates the shared installation, so the old project link is written by hand.
        $this->em()->getConnection()->executeStatement('UPDATE github_installations SET project_id = ? WHERE installation_id = ?', [(string) $project->id, $installationId]);

        return $installation;
    }

    private function repository(Project $project, ForgeRepositorySource $source, ?string $sourceRef): string
    {
        $repository = new ForgeRepository($project, 'github', 'ext-'.++$this->sequence.uniqid(), 'acme/repo-'.$this->sequence, $source, $sourceRef);
        $this->em()->persist($repository);
        $this->em()->flush();

        return ($repository->id ?? throw new \LogicException('The repository is not flushed.'))->toRfc4122();
    }

    /**
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    private function repositoriesWithAnswer(Connection $connection, array $candidates): array
    {
        $rows = $connection->fetchFirstColumn("SELECT repository_id FROM workflow_repository_settings WHERE setting_key = 'merge-approval'");

        return array_values(array_intersect($candidates, $rows));
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261107052310($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
