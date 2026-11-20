<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewCategory;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Inbox\Command\RunInboxActionCommand;
use App\Module\Inbox\Command\RunInboxActionHandler;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Service\CardWaitText;
use App\Module\Inbox\Service\InboxThreadActionBuilder;
use App\Module\Inbox\Service\ItemActions;
use App\Module\Inbox\View\ItemAction;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The inbox item of a work-limit pause, replayed from epic 1108: ten fix rounds for a failed agent review of PR #1302,
 * whose findings asked for the work of open children, ended blocked or waiting on the forge.
 */
final class WorkLimitPauseTest extends KernelTestCase
{
    use WorkflowProjects;

    private Project $project;
    private Card $epic;
    private ForgePullRequest $pullRequest;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('work-limit');
        $this->bindLifecycle($this->project);
        $em = $this->em();
        $this->epic = new Card(project: $this->project, column: $this->column($this->project, 'in-progress'), title: 'Make analytics workflow based', body: 'Body', number: 1108, type: 'epic');
        $em->persist($this->epic);
        $open = new Card(project: $this->project, column: $this->column($this->project, 'in-progress'), title: 'B3', body: 'Body', number: 1124);
        $open->parent = $this->epic;
        $next = new Card(project: $this->project, column: $this->column($this->project, 'next'), title: 'B5', body: 'Body', number: 1126);
        $next->parent = $this->epic;
        $done = new Card(project: $this->project, column: $this->column($this->project, 'done'), title: 'B4', body: 'Body', number: 1125);
        $done->parent = $this->epic;
        $em->persist($open);
        $em->persist($next);
        $em->persist($done);
        $this->pullRequest = new ForgePullRequest($this->project, 'github', 'loupe-ac/loupe', 1302);
        $this->pullRequest->headSha = '73d012e3ae054f19c47c3bf96659984f4b7a00b9';
        $em->persist($this->pullRequest);
        $em->flush();
    }

    public function test_the_title_and_the_body_say_what_looped_and_why(): void
    {
        $wait = $this->workLimitWait();
        $text = $this->service(CardWaitText::class);

        self::assertSame('10 fix rounds did not pass the agent review of PR #1302', $text->title([$wait], 'Make analytics workflow based', 200));
        $body = $text->body([$wait]);
        self::assertStringStartsWith('Card 1108 is paused. 10 fix rounds did not pass the agent review of PR #1302. Fix the cause, then retry the card. The latest agent review of PR #1302 found:', $body);
        self::assertStringContainsString("- The epic head still lacks B3 and B5\n- Preview: the Workflow Metrics page has no proven link", $body);
        self::assertStringContainsString('The last 10 fix rounds: 7 handed the pull request back to the review, 3 waiting for you.', $body);
        self::assertStringNotContainsString('waiting on the forge', $body);
        self::assertStringContainsString("The last fix round that stopped to ask you said:\n\n> A fix round cannot build child work. Merge the children first.", $body);
        self::assertStringContainsString('Wait for cards 1124 and 1126 to merge into `epic/1108`, then retry the card.', $body);
        self::assertStringContainsString('[Open pull request #1302](https://github.com/loupe-ac/loupe/pull/1302)', $body);
        self::assertStringContainsString('[Open the agent review](https://github.com/loupe-ac/loupe/runs/4242)', $body);
        self::assertMatchesRegularExpression('~\[Open the last run\]\(https?://[^)]+search=[0-9a-f-]{36}\)~', $body);
        self::assertStringNotContainsString('1125', (string) preg_replace('~[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}~', '', $body), 'A done child is not waited for.');
    }

    public function test_the_body_quotes_the_whole_summary_of_the_last_stopped_run(): void
    {
        $wait = $this->workLimitWait(lastOutput: "The children are open.\n\n1. Merge card 1124.\n2. Retry the card.");

        self::assertStringContainsString("The last fix round that stopped to ask you said:\n\n> The children are open.\n>\n> 1. Merge card 1124.\n> 2. Retry the card.", $this->service(CardWaitText::class)->body([$wait]));
    }

    public function test_the_body_reads_the_stage_result_of_an_older_run(): void
    {
        $wait = $this->workLimitWait(lastOutput: 'STAGE RESULT: blocked: spec finding needs the owner [reason: needs-person] Answer the question in the inbox.');

        self::assertStringContainsString("said:\n\n> spec finding needs the owner Answer the question in the inbox.", $this->service(CardWaitText::class)->body([$wait]));
    }

    public function test_the_body_leads_with_a_title_that_differs_from_the_summary(): void
    {
        $wait = $this->workLimitWait(lastOutput: 'Card 1124 holds the B3 work.', lastTitle: 'Merge the children first');

        self::assertStringContainsString("said:\n\n> Merge the children first\n>\n> Card 1124 holds the B3 work.", $this->service(CardWaitText::class)->body([$wait]));
    }

    public function test_a_resumed_round_counts_once_and_its_answered_stop_is_not_quoted(): void
    {
        $wait = $this->workLimitWait(rounds: [
            [1, [WorkerRunState::Blocked, WorkerRunState::WaitingOnForge]],
            [2, [WorkerRunState::WaitingOnForge]],
            [3, [WorkerRunState::Blocked, WorkerRunState::WaitingOnForge]],
        ], fires: 3);
        $text = $this->service(CardWaitText::class);
        $body = $text->body([$wait]);

        self::assertStringContainsString('The last 3 fix rounds all finished and handed the pull request back to the review. 2 of them first stopped to ask you, and went on.', $body);
        self::assertStringNotContainsString('stopped to ask you said', $body);
        self::assertStringNotContainsString('record of', $body);
    }

    public function test_the_walk_stops_at_round_one_of_the_current_cycle(): void
    {
        $wait = $this->workLimitWait(rounds: [
            [1, [WorkerRunState::Failed]],
            [2, [WorkerRunState::Failed]],
            [1, [WorkerRunState::WaitingOnForge]],
            [2, [WorkerRunState::WaitingOnForge]],
        ], fires: 2);

        $body = $this->service(CardWaitText::class)->body([$wait]);

        self::assertStringContainsString('The last 2 fix rounds all finished', $body);
        self::assertStringNotContainsString('failed', $body);
    }

    public function test_a_round_that_a_hold_gave_back_counts_its_newer_request_only(): void
    {
        $wait = $this->workLimitWait(rounds: [
            [1, [WorkerRunState::WaitingOnForge]],
            [2, [WorkerRunState::Failed]],
            [2, [WorkerRunState::WaitingOnForge]],
        ], fires: 2);

        self::assertStringContainsString('The last 2 fix rounds all finished', $this->service(CardWaitText::class)->body([$wait]));
    }

    public function test_a_round_with_no_run_is_not_called_failed(): void
    {
        $wait = $this->workLimitWait(rounds: [
            [1, [WorkerRunState::WaitingOnForge]],
            [2, []],
        ], fires: 2);

        $body = $this->service(CardWaitText::class)->body([$wait]);

        self::assertStringContainsString('The last 2 fix rounds: 1 has no run yet, 1 handed the pull request back to the review.', $body);
        self::assertStringNotContainsString('failed', $body);
        self::assertStringNotContainsString('all finished', $body);
    }

    public function test_older_requests_with_no_round_number_are_named_as_unknown(): void
    {
        $wait = $this->workLimitWait(rounds: [
            [null, [WorkerRunState::WaitingOnForge]],
            [null, [WorkerRunState::WaitingOnForge]],
            [3, [WorkerRunState::WaitingOnForge]],
            [4, [WorkerRunState::WaitingOnForge]],
        ], fires: 4);

        $body = $this->service(CardWaitText::class)->body([$wait]);

        self::assertStringContainsString('The last 2 fix rounds all finished', $body);
        self::assertStringContainsString('Loupe has no record of 2 counted rounds, which began before it stored round numbers.', $body);
    }

    public function test_a_pause_with_no_numbered_request_names_the_limit_as_unknown(): void
    {
        $wait = $this->workLimitWait(rounds: [
            [null, [WorkerRunState::Blocked]],
            [null, [WorkerRunState::WaitingOnForge]],
        ], fires: 2);

        $body = $this->service(CardWaitText::class)->body([$wait]);

        self::assertStringContainsString('Loupe has no record of 10 counted rounds', $body);
        self::assertStringNotContainsString('The last', $body);
        self::assertStringNotContainsString('stopped to ask you said', $body);
    }

    public function test_the_body_lists_what_the_review_found_before_each_round(): void
    {
        $wait = $this->workLimitWait(rounds: [
            [1, [WorkerRunState::WaitingOnForge]],
            [2, [WorkerRunState::WaitingOnForge]],
        ], fires: 2, earlierFindings: ['First finding', 'Second finding']);

        $body = $this->service(CardWaitText::class)->body([$wait]);

        self::assertStringContainsString("What the agent review found before each round, newest first:\n\n- Round 2: Second finding\n- Round 1: First finding", $body);
        self::assertStringContainsString("found:\n\n- The epic head still lacks B3 and B5", $body);
    }

    public function test_the_retry_says_what_it_grants_and_is_not_the_default_after_a_blocked_run(): void
    {
        $wait = $this->workLimitWait();

        $actions = $this->service(ItemActions::class)->forItem($wait->watch->item);

        $retry = self::action($actions, InboxActionKind::RetryCard);
        self::assertSame('Retry: 10 more fix rounds', $this->service(TranslatorInterface::class)->trans($retry->labelKey, $retry->labelParams));
        self::assertFalse($retry->default);
        $open = self::action($actions, InboxActionKind::OpenPullRequest);
        self::assertTrue($open->default);
        self::assertSame('https://github.com/loupe-ac/loupe/pull/1302', $open->href);

        $main = $this->service(InboxThreadActionBuilder::class)->build($wait->watch->item)->main;
        self::assertSame([InboxActionKind::OpenPullRequest, 'https://github.com/loupe-ac/loupe/pull/1302', true], [$main?->action->kind, $main?->href, $main?->external]);
    }

    public function test_a_failed_agent_review_offers_its_approval_on_github_and_a_hold(): void
    {
        $wait = $this->workLimitWait();

        $actions = $this->service(ItemActions::class)->forItem($wait->watch->item);

        self::assertSame('https://github.com/loupe-ac/loupe/pull/1302/files', self::action($actions, InboxActionKind::ApprovePullRequest)->href);
        self::action($actions, InboxActionKind::HoldCard);
        $rows = $this->service(InboxThreadActionBuilder::class)->build($wait->watch->item)->menu;
        $approve = array_find($rows, static fn ($row): bool => InboxActionKind::ApprovePullRequest === $row->action->kind);
        self::assertSame(['https://github.com/loupe-ac/loupe/pull/1302/files', true], [$approve?->href, $approve?->external]);
    }

    public function test_the_hold_makes_the_card_unmanaged_and_leaves_the_menu(): void
    {
        $wait = $this->workLimitWait();
        $item = $wait->watch->item;

        $action = $this->service(RunInboxActionHandler::class)(new RunInboxActionCommand($item, InboxActionKind::HoldCard, 'Children first', $this->project->owner));

        self::assertSame(InboxActionKind::HoldCard, $action?->kind);
        self::assertTrue($this->service(CardHoldRepository::class)->existsForCard($this->project, $this->epic->id ?? throw new \LogicException('The card is stored.')));
        $kinds = array_map(static fn (ItemAction $action): InboxActionKind => $action->kind, $this->service(ItemActions::class)->forItem($item));
        self::assertNotContains(InboxActionKind::HoldCard, $kinds);
    }

    public function test_a_passed_agent_review_offers_no_approval(): void
    {
        $wait = $this->workLimitWait(conclusion: AgentReviewConclusion::Success);

        $kinds = array_map(static fn (ItemAction $action): InboxActionKind => $action->kind, $this->service(ItemActions::class)->forItem($wait->watch->item));

        self::assertNotContains(InboxActionKind::ApprovePullRequest, $kinds);
        self::assertContains(InboxActionKind::HoldCard, $kinds);
    }

    public function test_the_retry_stays_the_default_when_the_last_run_succeeded(): void
    {
        $wait = $this->workLimitWait(lastState: WorkerRunState::Succeeded);

        $actions = $this->service(ItemActions::class)->forItem($wait->watch->item);

        self::assertTrue(self::action($actions, InboxActionKind::RetryCard)->default);
    }

    public function test_a_pause_whose_rule_is_gone_reads_as_before(): void
    {
        $wait = $this->workLimitWait(ruleId: 'removed-rule');

        self::assertSame('Paused: The rule requested the most work that it may request for this card', $this->service(CardWaitText::class)->title([$wait], 'Ship it', 200));
        $actions = $this->service(ItemActions::class)->forItem($wait->watch->item);
        self::assertSame('inbox.action.retry-card.label', self::action($actions, InboxActionKind::RetryCard)->labelKey);
        $kinds = array_map(static fn (ItemAction $action): InboxActionKind => $action->kind, $actions);
        self::assertNotContains(InboxActionKind::OpenPullRequest, $kinds);
        self::assertNotContains(InboxActionKind::ApprovePullRequest, $kinds);
        self::assertNotContains(InboxActionKind::HoldCard, $kinds);
    }

    /**
     * The 1108 sequence by default: ten fix rounds, 2 succeeded, 5 waiting on the forge and 3 blocked, the newest blocked, then the pause.
     *
     * @param list<array{?int, list<WorkerRunState>}>|null $rounds          the request round number and the run states of each round, oldest first
     * @param list<string>                                 $earlierFindings the titles of one failed review before each round, oldest first
     */
    private function workLimitWait(string $ruleId = 'fix-agent-review', WorkerRunState $lastState = WorkerRunState::Blocked, ?string $lastOutput = null, ?string $lastTitle = null, AgentReviewConclusion $conclusion = AgentReviewConclusion::Failure, ?array $rounds = null, int $fires = 10, array $earlierFindings = []): InboxCardWait
    {
        $em = $this->em();
        $cardId = $this->epic->id ?? throw new \LogicException('The card is stored.');
        $state = new WorkflowRuleState($cardId, $this->project, 'fix-agent-review');
        $state->subjectPullRequestId = $this->pullRequest->id;
        $state->fires = $fires;
        $em->persist($state);

        $states = [WorkerRunState::Succeeded, WorkerRunState::Succeeded, WorkerRunState::Blocked, WorkerRunState::WaitingOnForge, WorkerRunState::WaitingOnForge, WorkerRunState::WaitingOnForge, WorkerRunState::WaitingOnForge, WorkerRunState::WaitingOnForge, WorkerRunState::Blocked, $lastState];
        $rounds ??= array_map(static fn (WorkerRunState $runState, int $i): array => [$i + 1, [$runState]], $states, array_keys($states));
        $at = new \DateTimeImmutable('2026-10-27 22:20:00');
        $lastRun = array_key_last($rounds);
        foreach ($rounds as $i => [$number, $runStates]) {
            $opened = $at->modify(\sprintf('+%d minutes', $i * 10 - 1));
            $request = new WorkRequest($this->project, WorkSubject::CARD, $cardId, 1108, 'fix', null, 'fix-agent-review', $opened);
            $request->round = $number;
            $request->state = WorkRequestState::Done;
            $em->persist($request);
            $em->flush();
            foreach ($runStates as $j => $runState) {
                $last = $i === $lastRun && $j === array_key_last($runStates);
                $run = new WorkerRun($this->project, Uuid::v7(), WorkSubject::CARD, $cardId, 1108, 'fix', $runState, output: $last && null !== $lastOutput ? $lastOutput : 'A fix round cannot build child work. Merge the children first.', receivedAt: $at->modify(\sprintf('+%d minutes', $i * 10 + $j)), workRequestId: $request->id, ruleId: 'fix-agent-review');
                if ($last && null !== $lastTitle) {
                    $run->resultFields = ['title' => $lastTitle];
                }
                $em->persist($run);
            }
            if (isset($earlierFindings[$i])) {
                $em->persist(new AgentReview($this->project, $this->epic, $this->pullRequest, $this->pullRequest->headSha ?? '', 'Earlier finding.', AgentReviewConclusion::Failure, [
                    new AgentReviewFinding(null, null, null, AgentReviewSeverity::Important, $earlierFindings[$i], 'Fix it.', AgentReviewCategory::Spec),
                ], createdAt: $opened->modify('-2 minutes')));
            }
        }
        $em->persist(new WorkerRun($this->project, Uuid::v7(), WorkSubject::CARD, $cardId, 1108, 'review', WorkerRunState::Failed, receivedAt: $at, ruleId: 'agent-review'));

        $review = new AgentReview($this->project, $this->epic, $this->pullRequest, $this->pullRequest->headSha ?? '', 'The B3 and B5 deliverables remain absent.', $conclusion, [
            new AgentReviewFinding(null, null, null, AgentReviewSeverity::Important, 'The epic head still lacks B3 and B5', 'Merge B3 and B5.', AgentReviewCategory::Spec),
            new AgentReviewFinding(null, null, null, AgentReviewSeverity::Important, 'Preview: the Workflow Metrics page has no proven link', 'Add a link.', AgentReviewCategory::Spec),
        ], createdAt: new \DateTimeImmutable('2026-10-28 01:03:39'));
        $review->checkRunId = 4242;
        $em->persist($review);

        $pause = new CardPause($this->epic, $this->project, 'work-limit-reached', $ruleId, PauseKind::WorkLimit, new \DateTimeImmutable('2026-10-28 01:03:40'));
        $em->persist($pause);
        $em->flush();
        $item = new InboxItem(project: $this->project, number: 1, kind: InboxItemKind::Wait, title: 'Paused', blocking: true, origin: InboxAskOrigin::Loupe);
        $em->persist($item);
        $watch = new InboxCardWatch($item, $cardId, 1108);
        $em->persist($watch);
        $wait = new InboxCardWait($watch, 'paused', InboxCardWaitType::CardPause, 'marker.paused', pauseId: $pause->id, params: ['%reason%' => 'work-limit-reached']);
        $watch->waits->add($wait);
        $em->flush();

        return $wait;
    }

    /** @param list<ItemAction> $actions */
    private static function action(array $actions, InboxActionKind $kind): ItemAction
    {
        return array_find($actions, static fn (ItemAction $action): bool => $action->kind === $kind) ?? self::fail(\sprintf('The item offers %s.', $kind->value));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
