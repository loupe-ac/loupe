<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectDeleter;
use App\Module\Workflow\Entity\WorkflowPendingBaseline;
use App\Module\Workflow\Entity\WorkflowRepositorySetting;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Entity\WorkflowSettingOverride;
use App\Module\Workflow\EventListener\DeleteWorkflowDataOnProjectDeleting;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteWorkflowDataOnProjectDeletingTest extends KernelTestCase
{
    use WorkflowProjects;

    public function test_the_listener_deletes_the_setting_rows_of_the_project_alone(): void
    {
        self::bootKernel();
        $doomed = $this->workflowProject('settings-delete');
        $spared = $this->workflowProject('settings-spared');
        $this->settingRows($doomed);
        $this->settingRows($spared);
        self::assertSame([1, 1], $this->settingCounts((string) $doomed->id));

        $listener = self::getContainer()->get(DeleteWorkflowDataOnProjectDeleting::class);
        self::assertInstanceOf(DeleteWorkflowDataOnProjectDeleting::class, $listener);
        $listener(new ProjectDeleting($doomed));

        self::assertSame([0, 0], $this->settingCounts((string) $doomed->id));
        self::assertSame([1, 1], $this->settingCounts((string) $spared->id));
    }

    public function test_the_setting_rows_of_a_repository_go_with_the_repository(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('settings-repository');
        $this->settingRows($project);
        self::assertSame([1, 1], $this->settingCounts((string) $project->id));

        $this->em()->getConnection()->executeStatement('DELETE FROM forge_repositories WHERE project_id = :id', ['id' => (string) $project->id]);

        self::assertSame([0, 0], $this->settingCounts((string) $project->id));
    }

    private function settingRows(Project $project): void
    {
        $repository = new ForgeRepository($project, 'github', 'ext-'.uniqid(), 'acme/'.uniqid(), ForgeRepositorySource::Hook);
        $this->em()->persist($repository);
        $this->em()->flush();
        $repositoryId = $repository->id ?? throw new \LogicException('The repository is not flushed.');
        $this->em()->persist(new WorkflowSettingOverride($project, $repositoryId, 'merge-approval', '"agent"', true, 'blob', 'head'));
        $this->em()->persist(new WorkflowRepositorySetting($project, $repositoryId, 'epic-child-merge-method', '"merge"'));
        $this->em()->flush();
    }

    /** @return array{int, int} the override and stored setting rows of the project */
    private function settingCounts(string $projectId): array
    {
        $connection = $this->em()->getConnection();

        return [
            (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_setting_overrides WHERE project_id = :id', ['id' => $projectId]),
            (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_repository_settings WHERE project_id = :id', ['id' => $projectId]),
        ];
    }

    /** The foreign keys cascade too, so the project row stays to prove that the listener deletes the rows. */
    public function test_the_listener_deletes_the_workflow_rows_and_spares_a_sibling(): void
    {
        self::bootKernel();
        $doomed = $this->workflowProject('workflow-delete');
        $spared = $this->workflowProject('workflow-spared');
        $this->bindLifecycle($doomed);
        $this->bindLifecycle($spared);
        $this->ruleState($doomed);
        $this->ruleState($spared);
        self::assertSame([1, 5, 1, 1], $this->rowCounts((string) $doomed->id));

        $listener = self::getContainer()->get(DeleteWorkflowDataOnProjectDeleting::class);
        self::assertInstanceOf(DeleteWorkflowDataOnProjectDeleting::class, $listener);
        $listener(new ProjectDeleting($doomed));

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM projects WHERE id = :id', ['id' => (string) $doomed->id]));
        self::assertSame([0, 0, 0, 0], $this->rowCounts((string) $doomed->id));
        self::assertSame([1, 5, 1, 1], $this->rowCounts((string) $spared->id));
    }

    public function test_no_workflow_row_outlives_a_deleted_project(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('workflow-deleter');
        $this->bindLifecycle($project);
        $this->ruleState($project);
        $projectId = (string) $project->id;
        self::assertSame([1, 5, 1, 1], $this->rowCounts($projectId));

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($project);

        self::assertSame([0, 0, 0, 0], $this->rowCounts($projectId));
    }

    private function ruleState(Project $project): void
    {
        $card = new Card($project, $this->column($project, 'next'), 'Card', '', 1);
        $this->em()->persist($card);
        $this->em()->persist(new WorkflowRuleState($card->id ?? throw new \LogicException('The card is persisted.'), $project, 'start-design'));
        $this->em()->persist(new WorkflowPendingBaseline($card->id ?? throw new \LogicException('The card is persisted.'), $project));
        $this->em()->flush();
    }

    /** @return array{int, int, int, int} the binding, link, rule state and pending baseline rows of the project */
    private function rowCounts(string $projectId): array
    {
        $connection = $this->em()->getConnection();
        $id = ['id' => $projectId];

        return [
            (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_bindings WHERE project_id = :id', $id),
            (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_slot_links WHERE project_id = :id', $id),
            (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_rule_states WHERE project_id = :id', $id),
            (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_pending_baselines WHERE project_id = :id', $id),
        ];
    }
}
