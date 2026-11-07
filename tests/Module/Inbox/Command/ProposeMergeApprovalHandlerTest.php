<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\Forge\Service\RepositorySettingProposer;
use App\Module\Forge\Service\RepositorySettingProposers;
use App\Module\Inbox\Command\ProposeMergeApprovalCommand;
use App\Module\Inbox\Command\ProposeMergeApprovalHandler;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxMergeApprovalQuestion;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Repository\InboxMergeApprovalQuestionRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\RecordingSettingProposer;
use App\Tests\Module\Workflow\Service\WorkflowSettingsTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ProposeMergeApprovalHandlerTest extends WorkflowSettingsTestCase
{
    private Project $project;
    private ForgeRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('propose-merge');
        $this->repository = new ForgeRepository($this->em()->getReference(Project::class, $this->project->requireId()) ?? throw new \LogicException('No project.'), 'github', 'ext-'.uniqid(), 'acme/app', ForgeRepositorySource::Installation, '1');
        $this->em()->persist($this->repository);
        $this->em()->flush();
    }

    public function test_the_answer_becomes_a_pull_request_that_the_item_links(): void
    {
        $question = $this->question('person');
        $proposer = new RecordingSettingProposer(url: 'https://github.com/acme/app/pull/7');

        $this->handle($question, $proposer);

        self::assertSame([['acme/app', 'merge-approval', 'person']], array_map(static fn (array $call): array => [$call[0]->path, $call[1], $call[2]], $proposer->calls));
        self::assertSame('Set merge-approval in .loupe.yaml', $proposer->calls[0][3]);
        self::assertSame('https://github.com/acme/app/pull/7', $question->pullRequestUrl);
        self::assertStringContainsString('Loupe opened https://github.com/acme/app/pull/7. Merge it to make your answer count.', (string) $question->item->body);
    }

    public function test_a_second_run_for_the_same_answer_opens_nothing_more(): void
    {
        $question = $this->question('agent');
        $proposer = new RecordingSettingProposer(url: 'https://github.com/acme/app/pull/7');
        $this->handle($question, $proposer);

        $this->handle($question, $proposer);

        self::assertCount(1, $proposer->calls);
    }

    public function test_an_item_without_an_answer_opens_nothing(): void
    {
        $question = $this->question(null);
        $proposer = new RecordingSettingProposer(url: 'https://github.com/acme/app/pull/7');

        $this->handle($question, $proposer);

        self::assertSame([], $proposer->calls);
        self::assertNull($question->pullRequestUrl);
    }

    public function test_a_refusal_that_no_retry_fixes_gives_the_owner_the_lines_to_add(): void
    {
        $question = $this->question('agent');
        $proposer = new RecordingSettingProposer(failure: new PullRequestWriteFailed('permission', permanent: true));

        $this->handle($question, $proposer);

        self::assertNull($question->pullRequestUrl);
        self::assertStringContainsString("Add these lines to `.loupe.yaml` on the default branch of acme/app:\n\nworkflow:\n  merge-approval: agent", (string) $question->item->body);
    }

    public function test_a_failure_that_a_retry_can_fix_reaches_the_queue(): void
    {
        $question = $this->question('agent');
        $proposer = new RecordingSettingProposer(failure: new PullRequestWriteFailed('api_failed_rate_limited', permanent: false));

        $this->expectException(PullRequestWriteFailed::class);

        $this->handle($question, $proposer);
    }

    private function question(?string $answer): InboxMergeApprovalQuestion
    {
        $project = $this->em()->getReference(Project::class, $this->project->requireId()) ?? throw new \LogicException('No project.');
        $items = self::getContainer()->get(InboxItemRepository::class);
        self::assertInstanceOf(InboxItemRepository::class, $items);
        $item = new InboxItem($project, $items->nextNumber($project), InboxItemKind::Question, 'May Loupe merge in acme/app?', false, InboxAskOrigin::Loupe, 'Body', ['a', 'b']);
        $this->em()->persist($item);
        $question = new InboxMergeApprovalQuestion($project, $this->repository->id ?? throw new \LogicException('No id.'), $item, new \DateTimeImmutable());
        $question->answer = $answer;
        $this->em()->persist($question);
        $this->em()->flush();

        return $question;
    }

    private function handle(InboxMergeApprovalQuestion $question, RepositorySettingProposer $proposer): void
    {
        $container = self::getContainer();
        $handler = new ProposeMergeApprovalHandler(
            $container->get(InboxItemRepository::class),
            $container->get(InboxMergeApprovalQuestionRepository::class),
            $container->get(ForgeRepositoryRepository::class),
            new RepositorySettingProposers([$proposer]),
            $container->get(EntityManagerInterface::class),
            $container->get(TranslatorInterface::class),
            new NullLogger(),
            'en',
        );

        $handler(new ProposeMergeApprovalCommand((string) $question->item->id));
    }
}
