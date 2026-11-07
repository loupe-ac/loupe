<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Event\ForgeRepositoryCheckChanged;
use App\Module\Inbox\Command\AnswerInboxItemCommand;
use App\Module\Inbox\Command\AnswerInboxItemHandler;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxMergeApprovalQuestion;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Messenger\OpenMergeApprovalPullRequest;
use App\Module\Inbox\Repository\InboxMergeApprovalQuestionRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\QueuedPushes;
use App\Tests\Module\Workflow\Service\WorkflowSettingsTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class MergeApprovalQuestionReconcilerTest extends WorkflowSettingsTestCase
{
    use QueuedPushes;

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

        $this->project = $this->workflowProject('merge-question');
        $this->bindLifecycle($this->project);
        $this->resetServices();
    }

    public function test_a_repository_with_no_answer_opens_one_question_with_two_options(): void
    {
        $repository = $this->installed('acme/app');

        $this->deliver();

        $question = $this->onlyQuestion();
        $item = $question->item;
        self::assertSame(InboxItemKind::Question, $item->kind);
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertFalse($item->blocking);
        self::assertSame(InboxAskOrigin::Loupe, $item->origin);
        self::assertSame('May Loupe merge in acme/app?', $item->title);
        self::assertSame(['Let Loupe merge when the agent review passes', 'I approve each pull request on GitHub before Loupe merges it'], $item->options);
        self::assertSame($repository->id?->toRfc4122(), $question->repositoryId->toRfc4122());
        self::assertNull($question->endedAt);
        self::assertEquals([new NotifyInboxOwner((string) $item->id, NotifyInboxOwnerKind::Notice)], $this->queuedPushes());
    }

    public function test_a_repeated_event_opens_no_second_question(): void
    {
        $this->installed('acme/app');
        $this->deliver();
        $itemId = $this->onlyQuestion()->item->id?->toRfc4122();

        $this->deliver();
        $this->deliver();

        self::assertSame($itemId, $this->onlyQuestion()->item->id?->toRfc4122());
        self::assertCount(1, $this->queuedPushes());
    }

    public function test_a_repository_that_the_rules_make_a_person_review_is_not_asked(): void
    {
        $repository = $this->installed('acme/app');
        $this->rules($repository, requiresReview: true);

        $this->deliver();

        self::assertSame([], $this->questions());
    }

    public function test_a_repository_without_an_installation_is_not_asked(): void
    {
        $this->repository($this->project, 'acme/hook');

        $this->deliver();

        self::assertSame([], $this->questions());
    }

    public function test_a_project_with_the_inbox_switched_off_opens_nothing(): void
    {
        $this->installed('acme/app');
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[InboxInstallFlags::FLAG_INBOX_ENABLED]->value = false;
        $this->em()->flush();
        $reader = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();

        $this->deliver();

        self::assertSame([], $this->questions());
    }

    public function test_a_setting_in_the_file_closes_the_question_and_ends_its_row(): void
    {
        $repository = $this->installed('acme/app');
        $this->deliver();

        $this->read($this->project, $repository->id ?? throw new \LogicException('No id.'), ['merge-approval' => 'person']);
        $this->deliver();

        $question = $this->onlyQuestion();
        self::assertSame(InboxItemState::Resolved, $question->item->state);
        self::assertSame([InboxCloser::Loupe, InboxCloseCause::FactEnded], [$question->item->closedBy, $question->item->closeCause]);
        self::assertNotNull($question->endedAt);
    }

    public function test_an_answered_question_keeps_its_row_until_the_file_states_the_setting(): void
    {
        $repository = $this->installed('acme/app');
        $this->deliver();
        $question = $this->onlyQuestion();

        $this->answer($question, '0');
        $this->deliver();

        $kept = $this->onlyQuestion();
        self::assertSame($question->id?->toRfc4122(), $kept->id?->toRfc4122(), 'The answer opens no second question.');
        self::assertSame('agent', $kept->answer);
        self::assertNull($kept->endedAt);
        self::assertSame(InboxItemState::Resolved, $kept->item->state);

        $this->read($this->project, $repository->id ?? throw new \LogicException('No id.'), ['merge-approval' => 'agent']);
        $this->deliver();

        self::assertNotNull($this->onlyQuestion()->endedAt);
    }

    public function test_a_dismissed_question_is_not_opened_again_while_the_case_lasts(): void
    {
        $this->installed('acme/app');
        $this->deliver();
        $question = $this->onlyQuestion();
        $question->item->state = InboxItemState::Declined;
        $this->em()->flush();

        $this->deliver();

        self::assertCount(1, $this->questions());
        self::assertNull($this->onlyQuestion()->endedAt);
    }

    public function test_an_item_that_closed_without_an_answer_opens_a_new_question(): void
    {
        $this->installed('acme/app');
        $this->deliver();
        $first = $this->onlyQuestion();
        $first->item->state = InboxItemState::Cancelled;
        $this->em()->flush();

        $this->deliver();

        $all = $this->questions();
        self::assertCount(2, $all);
        self::assertCount(1, array_filter($all, static fn (InboxMergeApprovalQuestion $q): bool => null === $q->endedAt));
    }

    public function test_the_answer_stores_its_value_and_queues_the_pull_request(): void
    {
        $this->installed('acme/app');
        $this->deliver();
        $question = $this->onlyQuestion();

        $this->answer($question, '1');

        self::assertSame('person', $question->answer);
        $queued = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $this->jobs()->getSent()),
            static fn (object $message): bool => $message instanceof OpenMergeApprovalPullRequest,
        ));
        self::assertEquals([new OpenMergeApprovalPullRequest((string) $question->item->id)], $queued);
    }

    private function answer(InboxMergeApprovalQuestion $question, string $options): void
    {
        $handler = self::getContainer()->get(AnswerInboxItemHandler::class);
        self::assertInstanceOf(AnswerInboxItemHandler::class, $handler);
        $handler(new AnswerInboxItemCommand($question->item, $options, ''));
    }

    private function installed(string $path): ForgeRepository
    {
        $repository = new ForgeRepository($this->em()->getReference(Project::class, $this->project->requireId()) ?? throw new \LogicException('No project.'), 'github', 'ext-'.uniqid(), $path, ForgeRepositorySource::Installation, '1');
        $this->em()->persist($repository);
        $this->em()->flush();

        return $repository;
    }

    private function rules(ForgeRepository $repository, bool $requiresReview): void
    {
        $check = new ForgeRepositoryCheck($repository, $repository->project);
        $check->settings = [
            'defaultBranch' => 'main',
            'mergeMethods' => ['squash'],
            'loupeAppId' => 1,
            'rules' => ['pullRequestRule' => true, 'requiresReview' => $requiresReview, 'allowedMergeMethods' => null, 'requiredChecks' => [], 'approvalSources' => [], 'forbidsForcePush' => true, 'forbidsDeletion' => true],
            'rulesets' => [],
            'recentChecks' => [],
            'classicProtection' => false,
            'rulesUnavailable' => null,
        ];
        $this->em()->persist($check);
        $this->em()->flush();
    }

    private function deliver(): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new ForgeRepositoryCheckChanged($this->project->requireId()));
        $this->em()->clear();
    }

    /** @return list<InboxMergeApprovalQuestion> */
    private function questions(): array
    {
        $repository = self::getContainer()->get(InboxMergeApprovalQuestionRepository::class);
        self::assertInstanceOf(InboxMergeApprovalQuestionRepository::class, $repository);

        return $repository->findBy(['project' => $this->em()->getReference(Project::class, $this->project->requireId())]);
    }

    private function onlyQuestion(): InboxMergeApprovalQuestion
    {
        $questions = $this->questions();
        self::assertCount(1, $questions);

        return $questions[0];
    }
}
