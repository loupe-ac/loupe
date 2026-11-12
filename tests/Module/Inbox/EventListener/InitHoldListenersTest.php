<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\EventListener;

use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Event\ForgeBranchPushed;
use App\Module\Forge\Event\ForgePullRequestBranchChanged;
use App\Module\Forge\Messenger\CheckForgeRepository;
use App\Module\Forge\Messenger\CheckTrigger;
use App\Module\Forge\Service\InitPullRequestReader;
use App\Module\Inbox\Entity\InboxMergeApprovalQuestion;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Repository\InboxMergeApprovalQuestionRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\RepositoryWorkflowSettingsUnread;
use App\Tests\Module\Forge\FakeInitPullRequestReader;
use App\Tests\Module\Forge\FakeWorkflowFileReads;
use App\Tests\Module\Inbox\InitRunHolds;
use App\Tests\Module\Workflow\Service\WorkflowSettingsTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class InitHoldListenersTest extends WorkflowSettingsTestCase
{
    use InitRunHolds;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[InboxInstallFlags::FLAG_INBOX_ENABLED]->value = true;
        $this->em()->flush();
        $reader = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();

        $this->project = $this->workflowProject('init-hold');
        $this->bindLifecycle($this->project);
        $this->resetServices();
    }

    public function test_a_push_to_the_init_branch_reconciles_the_merge_question(): void
    {
        $this->installed('acme/app');

        $this->dispatch(new ForgeBranchPushed($this->project->requireId(), FakeInitPullRequestReader::FORGE, 'acme/app', InitPullRequestReader::BRANCH));

        self::assertCount(1, $this->questions());
    }

    public function test_a_push_to_another_branch_reconciles_nothing(): void
    {
        $this->installed('acme/app');

        $this->dispatch(new ForgeBranchPushed($this->project->requireId(), FakeInitPullRequestReader::FORGE, 'acme/app', 'feature/x'));

        self::assertSame([], $this->questions());
    }

    public function test_a_pull_request_from_the_init_branch_reconciles_the_merge_question(): void
    {
        $this->installed('acme/app');

        $this->dispatch(new ForgePullRequestBranchChanged($this->project->requireId(), FakeInitPullRequestReader::FORGE, 'acme/app', InitPullRequestReader::BRANCH));

        self::assertCount(1, $this->questions());
    }

    public function test_a_pull_request_from_another_branch_reconciles_nothing(): void
    {
        $this->installed('acme/app');

        $this->dispatch(new ForgePullRequestBranchChanged($this->project->requireId(), FakeInitPullRequestReader::FORGE, 'acme/app', 'feature/x'));

        self::assertSame([], $this->questions());
    }

    public function test_the_closed_init_pull_request_asks_the_question_again(): void
    {
        $this->installed('acme/app');
        $reader = $this->service(FakeInitPullRequestReader::class);
        $reader->answers = ['acme/app' => true];
        $this->dispatch(new ForgePullRequestBranchChanged($this->project->requireId(), FakeInitPullRequestReader::FORGE, 'acme/app', InitPullRequestReader::BRANCH));
        self::assertSame([], $this->questions());

        $reader->answers = [];
        $this->dispatch(new ForgePullRequestBranchChanged($this->project->requireId(), FakeInitPullRequestReader::FORGE, 'acme/app', InitPullRequestReader::BRANCH));

        self::assertCount(1, $this->questions());
    }

    public function test_an_unread_file_reconciles_the_merge_question_with_the_stored_setting(): void
    {
        $repository = $this->installed('acme/app');

        $this->dispatch(new RepositoryWorkflowSettingsUnread($this->project->requireId(), $repository->id ?? throw new \LogicException('No id.')));

        self::assertCount(1, $this->questions());
    }

    public function test_the_end_of_an_init_run_queues_a_forced_read_and_a_check_for_each_repository_with_access(): void
    {
        $open = $this->installed('acme/open');
        $lost = $this->installed('acme/lost');
        $lost->accessLostAt = new \DateTimeImmutable();
        $second = $this->installed('acme/second');
        $this->installed('acme/hook', ForgeRepositorySource::Hook);
        $this->em()->flush();
        $run = $this->startInit($this->project);
        self::assertSame([], $this->service(FakeWorkflowFileReads::class)->queued);
        self::assertSame([], $this->queuedChecks());

        $this->endInit($this->project, $run);

        self::assertEqualsCanonicalizing([['acme/open', true], ['acme/second', true]], $this->service(FakeWorkflowFileReads::class)->queued);
        self::assertEqualsCanonicalizing(
            [new CheckForgeRepository((string) $open->id, CheckTrigger::InitEnded), new CheckForgeRepository((string) $second->id, CheckTrigger::InitEnded)],
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->queuedChecks()),
        );
    }

    public function test_a_closed_run_of_another_kind_queues_nothing(): void
    {
        $this->installed('acme/app');
        $other = $this->interactiveRuns()->recordLaunch($this->managedProject($this->project), new WorkSubject(WorkSubject::PROJECT, $this->project->requireId()), null, Uuid::v4(), 'other-session', Uuid::v4())[0];

        $this->endInit($this->project, $other);

        self::assertSame([], $this->service(FakeWorkflowFileReads::class)->queued);
        self::assertSame([], $this->queuedChecks());
    }

    private function dispatch(object $event): void
    {
        $this->service(EventDispatcherInterface::class)->dispatch($event);
        $this->em()->clear();
    }

    private function installed(string $path, ForgeRepositorySource $source = ForgeRepositorySource::Installation): ForgeRepository
    {
        $repository = new ForgeRepository($this->em()->getReference(Project::class, $this->project->requireId()) ?? throw new \LogicException('No project.'), FakeInitPullRequestReader::FORGE, 'ext-'.uniqid(), $path, $source, '1');
        $this->em()->persist($repository);
        $this->em()->flush();

        return $repository;
    }

    /** @return list<InboxMergeApprovalQuestion> */
    private function questions(): array
    {
        return $this->service(InboxMergeApprovalQuestionRepository::class)->findBy(['project' => $this->em()->getReference(Project::class, $this->project->requireId())]);
    }

    /** @return list<Envelope> */
    private function queuedChecks(): array
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(
            $transport->getSent(),
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof CheckForgeRepository,
        ));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
