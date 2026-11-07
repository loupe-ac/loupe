<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Contract\RepositoryWorkflowSettingsApplied;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class WorkflowSettingsWriterTest extends WorkflowSettingsTestCase
{
    public function test_a_known_key_with_an_allowed_value_stores_a_valid_row(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('writer-valid');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');

        $result = $this->writer()->apply($project->requireId(), $repository, 'blob-1', 'head-1', ['merge-approval' => 'person'], true);

        self::assertSame([], $result->warnings);
        $row = $this->overrideRow($repository, 'merge-approval');
        self::assertTrue($row->valid);
        self::assertSame('"person"', $row->value);
        self::assertSame('blob-1', $row->blobSha);
        self::assertSame('head-1', $row->headSha);
    }

    public function test_every_read_announces_that_it_applied_the_settings_of_the_repository(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('writer-event');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $seen = [];
        $dispatcher->addListener(RepositoryWorkflowSettingsApplied::class, static function (RepositoryWorkflowSettingsApplied $event) use (&$seen): void {
            $seen[] = [$event->projectId->toRfc4122(), $event->repositoryId->toRfc4122()];
        });

        $this->writer()->apply($project->requireId(), $repository, 'blob-1', 'head-1', ['merge-approval' => 'person'], true);
        $this->writer()->apply($project->requireId(), $repository, null, 'head-2', [], false);

        $expected = [$project->requireId()->toRfc4122(), $repository->toRfc4122()];
        self::assertSame([$expected, $expected], $seen, 'A read that finds a missing file announces too, because the answer to the merge question can end.');
    }

    public function test_a_known_key_with_a_value_that_is_not_allowed_stores_an_invalid_row_and_warns(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('writer-invalid');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');

        $result = $this->writer()->apply($project->requireId(), $repository, 'blob-1', 'head-1', ['merge-approval' => 'maybe', 'epic-branches' => 'yes'], true);

        $row = $this->overrideRow($repository, 'merge-approval');
        self::assertFalse($row->valid);
        self::assertNull($row->value);
        self::assertFalse($this->overrideRow($repository, 'epic-branches')->valid);
        self::assertCount(2, $result->warnings);
        self::assertStringContainsString('merge-approval', $result->warnings[0]);
        self::assertStringContainsString('agent, person', $result->warnings[0]);
    }

    public function test_an_unknown_key_stores_nothing_and_warns(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('writer-unknown');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');

        $result = $this->writer()->apply($project->requireId(), $repository, 'blob-1', 'head-1', ['no-such-key' => 'x'], true);

        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('no-such-key', $result->warnings[0]);
        self::assertSame(0, $this->rows('workflow_setting_overrides', $repository));
    }

    public function test_a_key_that_the_file_no_longer_holds_loses_its_row(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('writer-gone');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');
        $this->read($project, $repository, ['merge-approval' => 'agent', 'epic-child-merge-method' => 'merge']);
        self::assertSame(2, $this->rows('workflow_setting_overrides', $repository));

        $this->read($project, $repository, ['merge-approval' => 'person'], blob: 'blob-2');

        self::assertSame(1, $this->rows('workflow_setting_overrides', $repository));
        self::assertSame('"person"', $this->overrideRow($repository, 'merge-approval')->value);
    }

    public function test_a_file_that_is_missing_or_names_another_project_removes_every_row_and_keeps_the_stored_rows(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('writer-unusable');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');
        $this->store($project, $repository, 'merge-approval', 'agent');
        $this->store($project, $repository, 'epic-child-merge-method', 'merge');
        $this->read($project, $repository, ['epic-child-merge-method' => 'rebase']);
        self::assertSame(1, $this->rows('workflow_setting_overrides', $repository));
        self::assertSame(1, $this->rows('workflow_repository_settings', $repository));

        $this->read($project, $repository, ['merge-approval' => 'person'], usable: false, blob: null);

        self::assertSame(0, $this->rows('workflow_setting_overrides', $repository));
        self::assertSame(1, $this->rows('workflow_repository_settings', $repository));
    }

    public function test_a_known_key_with_a_row_deletes_its_stored_row_whether_the_value_is_valid_or_not(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('writer-stored');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');
        $this->store($project, $repository, 'merge-approval', 'agent');
        $this->store($project, $repository, 'epic-child-merge-method', 'merge');
        self::assertSame(2, $this->rows('workflow_repository_settings', $repository));

        $this->read($project, $repository, ['merge-approval' => 'maybe']);

        self::assertSame(1, $this->rows('workflow_repository_settings', $repository));
        self::assertSame('"merge"', $this->em()->getConnection()->fetchOne('SELECT value FROM workflow_repository_settings WHERE repository_id = :id', ['id' => $repository->toRfc4122()]));

        $this->read($project, $repository, ['epic-child-merge-method' => 'squash'], blob: 'blob-2');

        self::assertSame(0, $this->rows('workflow_repository_settings', $repository));
    }

    public function test_an_unknown_key_deletes_no_stored_row(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('writer-unknown-stored');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');
        $this->store($project, $repository, 'merge-approval', 'agent');

        $this->read($project, $repository, ['no-such-key' => 'x']);

        self::assertSame(1, $this->rows('workflow_repository_settings', $repository));
    }

    public function test_the_stored_row_of_another_repository_stays(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('writer-sibling');
        $this->bindLifecycle($project);
        $first = $this->repository($project, 'acme/first');
        $second = $this->repository($project, 'acme/second');
        $this->store($project, $first, 'merge-approval', 'agent');
        $this->store($project, $second, 'merge-approval', 'agent');

        $this->read($project, $first, ['merge-approval' => 'person']);

        self::assertSame(0, $this->rows('workflow_repository_settings', $first));
        self::assertSame(1, $this->rows('workflow_repository_settings', $second));
    }

    public function test_the_blob_of_the_stored_rows_is_unchanged_only_for_the_same_sha(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('writer-blob');
        $this->bindLifecycle($project);
        $repository = $this->repository($project, 'acme/app');

        self::assertFalse($this->writer()->isBlobUnchanged($repository, 'blob-1'));

        $this->read($project, $repository, ['merge-approval' => 'agent']);

        self::assertTrue($this->writer()->isBlobUnchanged($repository, 'blob-1'));
        self::assertFalse($this->writer()->isBlobUnchanged($repository, 'blob-2'));
    }
}
