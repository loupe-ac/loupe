<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\AgentReview\Service\AgentReviewNoticeBody;
use App\Module\AgentReview\Service\UncommentedAgentReviews;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Command\ReleaseCardPauseHandler;
use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\PullRequestComment;
use App\Module\Board\Entity\PullRequestNotice;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\PullRequestCommentRepository;
use App\Module\Board\Repository\PullRequestNoticeRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Board\Service\StaleApprovalNoticeBody;
use App\Module\Board\Workflow\BoardCardPauses;
use App\Module\Board\Workflow\CardTypeFacts;
use App\Module\Board\Workflow\ForgeWrite;
use App\Module\Board\Workflow\MoveCard;
use App\Module\Board\Workflow\RequestWork;
use App\Module\Board\Workflow\WorkRequestContexts;
use App\Module\Board\Workflow\WorkRequestOpener;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\CardHoldsReleased;
use App\Module\Bridge\EventListener\RearmCardsOnCardHoldsReleased;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\Service\WorkTakers;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\Workflow\BridgeWorkLedger;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\ForgePullRequestWrites;
use App\Module\Forge\Service\PullRequestBaseChangers;
use App\Module\Forge\Service\PullRequestBranchUpdaters;
use App\Module\Forge\Service\PullRequestMergers;
use App\Module\Forge\Service\PullRequestSyncFailed;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Action\Ask;
use App\Module\Workflow\Action\EvaluateChildren;
use App\Module\Workflow\Action\MissingAction;
use App\Module\Workflow\Action\PauseCard;
use App\Module\Workflow\Action\ReleasePause;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use App\Module\Workflow\Command\ReleaseWorkflowPauseCommand;
use App\Module\Workflow\Command\ReleaseWorkflowPauseHandler;
use App\Module\Workflow\Condition\Conditions;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\BlockerHoldChanged;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Contract\CardMarkerStore;
use App\Module\Workflow\Contract\EpicBranches;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\KindPrompts;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\PersonWork;
use App\Module\Workflow\Contract\RetryNotes;
use App\Module\Workflow\Contract\WorkflowRuleStates;
use App\Module\Workflow\Contract\WorkHolds;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Engine\MarkerPass;
use App\Module\Workflow\Engine\RuleSubject;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use App\Module\Workflow\Event\CardPaused;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Messenger\EvaluateCardHandler;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowPendingBaselineRepository;
use App\Module\Workflow\Repository\WorkflowQueuedEvaluationRepository;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Module\Workflow\Service\FactFingerprint;
use App\Module\Workflow\Service\FactProviders;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Service\PassMemo;
use App\Module\Workflow\Service\WorkflowAutomation;
use App\Module\Workflow\Template\AppRules;
use App\Module\Workflow\Template\ProjectTemplateCopy;
use App\Module\Workflow\Template\TemplateMetricParser;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplatePrompts;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Board\Workflow\FakeForgeWriter;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Module\Workflow\Action\FakeRuleAsks;
use App\Tests\Module\Workflow\Action\ListedTestAction;
use App\Tests\Module\Workflow\Fact\ListsDocumentsReady;
use App\Tests\Module\Workflow\Fact\ProvidedFacts;
use App\Tests\Module\Workflow\Fact\ProvidedFactsProvider;
use App\Tests\Module\Workflow\Fact\ProvidedFactsReady;
use App\Tests\Module\Workflow\Fact\UnprovidedFactsReady;
use App\Tests\Module\Workflow\FakeWorkHolds;
use App\Tests\Module\Workflow\Template\AppRulesTest;
use App\Tests\Support\EpicBranchesExist;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AuditBundle\Auditor;

final class EngineTest extends KernelTestCase
{
    use ActionScenario;
    use EpicBranchesExist;

    private const string NOON = '2026-10-02 12:00:00';

    private const string HEAD_ONE = 'aaaaaaa1';

    private const string HEAD_TWO = 'bbbbbbb2';

    private const array ALWAYS = ['all' => []];

    private const array NOT_EPIC = ['not' => ['card.type' => ['types' => ['epic']]]];

    private const array PROVIDED_READY = [ProvidedFactsReady::KEY => []];

    /** A rule that reads the open work requests of the card, so its own request changes its facts. */
    private const array IDLE = ['all' => [self::NOT_EPIC, ['not' => ['card.run.work_active' => ['kind' => 'implement']]]]];

    private const array RETRY_ONCE = ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]];

    private const array REPAIR = ['retryOn' => ['failed', 'timeout'], 'retries' => 1, 'backoffMinutes' => [10, 60], 'repair' => ['kind' => 'repair']];

    /** @var list<CardPaused> */
    private array $paused = [];

    /** @var list<BlockerHoldChanged> */
    private array $changed = [];

    private RecordingLogger $logger;

    private ?CardEvaluations $evaluations = null;

    /** @var (\Closure(): void)|null */
    private ?\Closure $settleJoinedWork = null;

    private ?AppRules $appRules = null;

    private FakeRuleAsks $asks;

    private FakeWorkHolds $workHolds;

    #[\Override]
    protected function setUp(): void
    {
        ListedTestAction::$log = [];
        ListedTestAction::$facts = [];
        ListedTestAction::$afterRun = null;
        $this->logger = new RecordingLogger();
        $this->asks = new FakeRuleAsks();
        $this->workHolds = new FakeWorkHolds();
    }

    public function test_a_rule_fires_on_its_rising_edge_once(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);

        $this->evaluate($card);
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertCount(1, $this->liveRequests($card));
        $state = $this->ruleState($card, 'work');
        self::assertTrue($state->truth);
        self::assertSame(1, $state->fires);
    }

    public function test_a_request_in_a_held_project_opens_nothing_counts_no_attempt_and_marks_the_rule_state(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->workHolds->held = true;

        $this->evaluate($card);

        self::assertSame([], $this->liveRequests($card));
        self::assertNull($this->activePause($card));
        $state = $this->ruleState($card, 'work');
        self::assertSame([false, 0, 0, null, null], [$state->truth, $state->attempts, $state->fires, $state->dueAt, $state->lastRefusal]);
        self::assertSame(self::NOON, $state->heldByWorkHoldSince?->format('Y-m-d H:i:s'));
        self::assertSame(['held'], array_column($this->firedRecords()[0]['context']['fired'], 'outcome'));
    }

    public function test_a_held_rule_keeps_its_first_mark_and_asks_again_when_the_hold_ends(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->workHolds->held = true;
        $this->evaluate($card);

        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(self::NOON, $this->ruleState($card, 'work')->heldByWorkHoldSince?->format('Y-m-d H:i:s'));
        self::assertSame([], $this->liveRequests($card));

        $this->workHolds->held = false;
        $this->evaluate($card, '2026-10-02 12:10:00');

        self::assertCount(1, $this->liveRequests($card));
        $state = $this->ruleState($card, 'work');
        self::assertNull($state->heldByWorkHoldSince);
        self::assertTrue($state->truth);
        self::assertSame(1, $state->fires);
    }

    public function test_a_rule_that_stops_holding_loses_the_work_hold_mark(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::IDLE)]);
        $this->workHolds->held = true;
        $this->evaluate($card);
        self::assertNotNull($this->ruleState($card, 'work')->heldByWorkHoldSince);

        $this->setType($card, 'epic');
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertNull($this->ruleState($card, 'work')->heldByWorkHoldSince);
    }

    public function test_the_later_rules_of_a_card_run_while_an_earlier_request_is_held(): void
    {
        $card = $this->boundCard([
            self::requestRule('work', self::ALWAYS),
            self::listRule('later', [self::listed('later')], self::ALWAYS),
        ]);
        $this->workHolds->held = true;

        $this->evaluate($card);

        self::assertSame(['later'], ListedTestAction::$log);
        self::assertNotNull($this->ruleState($card, 'work')->heldByWorkHoldSince);
        self::assertNull($this->ruleState($card, 'later')->heldByWorkHoldSince);
    }

    public function test_a_held_retry_clears_its_due_time_and_waits_for_the_hold_end(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::RETRY_ONCE);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        self::assertNotNull($this->ruleState($card, 'work')->dueAt);
        $attempts = $this->ruleState($card, 'work')->attempts;

        $this->workHolds->held = true;
        $this->evaluate($card, '2026-10-02 12:31:00');

        $state = $this->ruleState($card, 'work');
        self::assertSame([$attempts, null], [$state->attempts, $state->dueAt]);
        self::assertNotNull($state->heldByWorkHoldSince);
        self::assertNull($this->activePause($card));
        self::assertSame([], $this->liveRequests($card));
        self::assertSame([], $this->service(WorkflowRuleStateRepository::class)->findDueCardIds(new \DateTimeImmutable('2026-10-02 18:00:00'), 10));
    }

    public function test_a_repair_request_in_a_held_project_opens_nothing_and_pauses_nothing(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $this->evaluate($card, '2026-10-02 12:31:00');
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:40:00');
        $this->workHolds->held = true;

        $this->evaluate($card, '2026-10-02 12:41:00');

        self::assertSame([], $this->liveRequests($card));
        self::assertNull($this->activePause($card));
        $state = $this->ruleState($card, 'work');
        self::assertFalse($state->repaired);
        self::assertNotNull($state->heldByWorkHoldSince);

        $this->workHolds->held = false;
        $this->evaluate($card, '2026-10-02 13:00:00');

        self::assertCount(1, $this->liveRequests($card));
        self::assertNull($this->ruleState($card, 'work')->heldByWorkHoldSince);
    }

    public function test_a_rule_that_joins_another_rules_live_request_fires_its_own_when_that_request_ends(): void
    {
        $card = $this->joinedCard();
        $review = $this->liveRequestOfKind($card, 'fix');

        $this->finish($review, '2026-10-02 12:10:00');
        $this->evaluate($card, '2026-10-02 12:11:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('checks', $live[0]->ruleId);
        $state = $this->ruleState($card, 'checks');
        self::assertSame([true, 1, $live[0]->id?->toRfc4122()], [$state->truth, $state->fires, $state->workRequestId?->toRfc4122()]);

        $this->evaluate($card, '2026-10-02 12:12:00');
        self::assertSame($live, $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'checks')->fires);
    }

    public function test_a_rule_that_joins_another_rules_live_request_fires_its_own_when_that_request_is_refused(): void
    {
        $card = $this->joinedCard();

        $this->refuse($this->liveRequestOfKind($card, 'fix'), 'failed', '2026-10-02 12:10:00');
        $this->evaluate($card, '2026-10-02 12:11:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('checks', $live[0]->ruleId);
        $state = $this->ruleState($card, 'checks');
        self::assertSame([1, 0], [$state->fires, $state->attempts]);
    }

    public function test_a_rule_that_joins_another_rules_request_which_settles_right_after_the_answer_fires_its_own(): void
    {
        $card = $this->boundCard([
            self::requestRule('review', self::ALWAYS, kind: 'fix'),
            self::requestRule('checks', ['card.type' => ['types' => ['bug']]], kind: 'fix'),
        ]);
        $this->evaluate($card);
        $review = $this->liveRequestOfKind($card, 'fix');

        $this->setType($card, 'bug');
        $this->settleJoinedWork = fn () => $this->finish($review, '2026-10-02 12:05:00');
        $this->evaluate($card, '2026-10-02 12:05:00');
        $this->settleJoinedWork = null;
        self::assertSame([], $this->liveRequests($card));
        self::assertFalse($this->ruleState($card, 'checks')->truth);

        $this->evaluate($card, '2026-10-02 12:06:00');

        self::assertSame('checks', $this->liveRequestOfKind($card, 'fix')->ruleId);
        self::assertSame(1, $this->ruleState($card, 'checks')->fires);
    }

    public function test_a_rule_whose_own_request_settles_right_after_the_answer_fires_no_second_request(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['card.type' => ['types' => ['bug']]])]);
        $this->setType($card, 'bug');
        $this->evaluate($card);
        $own = $this->liveRequests($card)[0];
        $this->setType($card, 'feature');
        $this->evaluate($card, '2026-10-02 12:05:00');

        $this->setType($card, 'bug');
        $this->settleJoinedWork = fn () => $this->finish($own, '2026-10-02 12:06:00');
        $this->evaluate($card, '2026-10-02 12:06:00');
        $this->settleJoinedWork = null;
        self::assertSame([], $this->liveRequests($card));
        self::assertTrue($this->ruleState($card, 'fix')->truth);

        $this->evaluate($card, '2026-10-02 12:07:00');

        self::assertSame([], $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_rule_that_joined_another_rules_request_opens_nothing_when_it_no_longer_holds(): void
    {
        $card = $this->joinedCard();

        $this->setType($card, 'feature');
        $this->evaluate($card, '2026-10-02 12:08:00');
        $this->finish($this->liveRequestOfKind($card, 'fix'), '2026-10-02 12:10:00');
        $this->evaluate($card, '2026-10-02 12:11:00');

        self::assertSame([], $this->liveRequests($card));
        self::assertSame([false, 0], [$this->ruleState($card, 'checks')->truth, $this->ruleState($card, 'checks')->fires]);
    }

    public function test_a_retry_that_joins_another_rules_live_request_counts_its_refusal_once(): void
    {
        $card = $this->boundCard([
            self::requestRule('review', ['card.type' => ['types' => ['bug']]], kind: 'fix'),
            self::requestRule('checks', self::ALWAYS, kind: 'fix'),
        ], onWorkFailed: self::RETRY_ONCE);
        $this->evaluate($card);
        $this->refuse($this->liveRequestOfKind($card, 'fix'), 'failed', '2026-10-02 12:05:00');
        $this->evaluate($card, '2026-10-02 12:06:00');
        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 12:07:00');
        $review = $this->liveRequestOfKind($card, 'fix');
        self::assertSame('review', $review->ruleId);

        $this->evaluate($card, '2026-10-02 12:17:00');
        $this->evaluate($card, '2026-10-02 12:18:00');
        $this->evaluate($card, '2026-10-02 12:19:00');
        self::assertNull($this->activePause($card));
        self::assertSame(1, $this->ruleState($card, 'checks')->attempts);

        $this->finish($review, '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        self::assertSame('checks', $this->liveRequestOfKind($card, 'fix')->ruleId);
        self::assertNull($this->activePause($card));
    }

    public function test_a_rule_that_finds_its_own_request_live_fires_no_second_request_when_it_ends(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['card.type' => ['types' => ['bug']]])]);
        $this->setType($card, 'bug');
        $this->evaluate($card);
        $this->setType($card, 'feature');
        $this->evaluate($card, '2026-10-02 12:05:00');
        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 12:06:00');
        self::assertTrue($this->ruleState($card, 'fix')->truth);

        $this->finish($this->liveRequests($card)[0], '2026-10-02 12:10:00');
        $this->evaluate($card, '2026-10-02 12:11:00');

        self::assertSame([], $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);
    }

    private const array FIX_CAUSES = ['all' => [['pr.open' => []], ['any' => [['pr.checks_failed' => []], ['pr.conflicting' => []], ['pr.changes_requested' => []]]]]];

    public function test_a_conflict_seen_with_the_fix_push_opens_a_second_fix_when_the_first_ends(): void
    {
        $card = $this->boundCard([self::requestRule('fix', self::FIX_CAUSES, limit: 3, role: 'fix')]);
        $pullRequest = $this->pullRequest($card, headSha: 'a');
        $this->requestChanges($pullRequest);
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];
        self::assertSame(['pr.open{}', 'pr.changes_requested{}'], $this->ruleState($card, 'fix')->causesAtOpen);

        $pullRequest->headSha = 'b';
        $pullRequest->mergeability = PullRequestMergeability::Conflicting;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertTrue($this->ruleState($card, 'fix')->truth, 'One read saw the push and the conflict together, so no edge happened.');
        $this->finish($first, '2026-10-02 12:10:00');
        $this->evaluate($card, '2026-10-02 12:11:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertNotSame($first->id?->toRfc4122(), $live[0]->id?->toRfc4122());
        self::assertSame(2, $this->ruleState($card, 'fix')->fires);
        self::assertSame(['changes-requested', 'conflict'], array_column($this->fixEvents($card), 'reason'));
    }

    public function test_a_conflict_after_a_push_during_the_own_fix_opens_a_second_fix_when_the_first_ends(): void
    {
        $card = $this->boundCard([self::requestRule('fix', self::FIX_CAUSES, limit: 3, role: 'fix')]);
        $pullRequest = $this->pullRequest($card, headSha: 'a');
        $this->requestChanges($pullRequest);
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];
        $pullRequest->headSha = 'b';
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:03:00');
        self::assertTrue($this->ruleState($card, 'fix')->truth, 'The push does not answer the review.');

        $pullRequest->mergeability = PullRequestMergeability::Conflicting;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertSame($first->id?->toRfc4122(), $this->ruleState($card, 'fix')->workRequestId?->toRfc4122());
        self::assertSame(['pr.open{}', 'pr.changes_requested{}'], $this->ruleState($card, 'fix')->causesAtOpen);

        $this->finish($first, '2026-10-02 12:10:00');
        $this->evaluate($card, '2026-10-02 12:11:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertNotSame($first->id?->toRfc4122(), $live[0]->id?->toRfc4122());
        self::assertSame(['changes-requested', 'conflict'], array_column($this->fixEvents($card), 'reason'));
    }

    public function test_a_negated_cause_that_starts_to_hold_during_the_fix_opens_a_second_fix(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['any' => [['pr.conflicting' => []], ['not' => ['pr.checks_passed' => []]]]], limit: 3)]);
        $pullRequest = $this->pullRequest($card);
        $pullRequest->mergeability = PullRequestMergeability::Conflicting;
        $pullRequest->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];
        self::assertSame(['pr.conflicting{}'], $this->ruleState($card, 'fix')->causesAtOpen);

        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:05:00');
        $this->finish($first, '2026-10-02 12:10:00');
        $this->evaluate($card, '2026-10-02 12:11:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertNotSame($first->id?->toRfc4122(), $live[0]->id?->toRfc4122());
        self::assertSame(2, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_fix_that_ends_with_the_same_causes_opens_no_second_fix(): void
    {
        $card = $this->boundCard([self::requestRule('fix', self::FIX_CAUSES, limit: 3, role: 'fix')]);
        $this->requestChanges($this->pullRequest($card, headSha: 'a'));
        $this->evaluate($card);

        $this->finish($this->liveRequests($card)[0], '2026-10-02 12:10:00');
        $this->evaluate($card, '2026-10-02 12:11:00');
        $this->evaluate($card, '2026-10-02 12:12:00');

        self::assertSame([], $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);
        self::assertFalse($this->ruleState($card, 'fix')->truth, 'The fix run answered the review, so nothing holds.');
    }

    public function test_an_all_only_rule_that_turns_false_and_true_during_its_request_opens_no_second_request(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 3)]);
        $pullRequest = $this->pullRequest($card);
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];

        $pullRequest->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:03:00');
        self::assertFalse($this->ruleState($card, 'fix')->truth);
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertSame($first->id?->toRfc4122(), $this->ruleState($card, 'fix')->workRequestId?->toRfc4122());

        $this->finish($first, '2026-10-02 12:10:00');
        $this->evaluate($card, '2026-10-02 12:11:00');
        $this->evaluate($card, '2026-10-02 12:12:00');

        self::assertSame([], $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_second_fix_for_a_new_cause_waits_while_another_fix_rule_holds_the_slot(): void
    {
        $card = $this->boundCard([
            self::requestRule('other', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 3, kind: 'fix'),
            self::requestRule('fix', ['all' => [['pr.open' => []], ['any' => [['pr.conflicting' => []], ['pr.changes_requested' => []]]]]], limit: 3),
        ]);
        $pullRequest = $this->pullRequest($card, headSha: 'a');
        $this->requestChanges($pullRequest);
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];
        self::assertSame('fix', $first->ruleId);

        $pullRequest->mergeability = PullRequestMergeability::Conflicting;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:05:00');
        $this->finish($first, '2026-10-02 12:10:00');
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:11:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('other', $live[0]->ruleId, 'The other fix rule took the slot first.');
        self::assertFalse($this->ruleState($card, 'fix')->truth, 'The fix waits for the slot.');
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);

        $this->finish($live[0], '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('fix', $live[0]->ruleId);
        self::assertSame(2, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_rule_outside_the_slot_resets_cancels_its_request_and_fires_again_on_reentry(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];

        $this->moveTo($card, 'in-progress');
        $this->evaluate($card);

        self::assertSame(WorkRequestState::Cancelled, $first->state);
        self::assertSame([], $this->liveRequests($card));
        $state = $this->ruleState($card, 'work');
        self::assertFalse($state->truth);
        self::assertSame(0, $state->fires);

        $this->moveTo($card, 'next');
        $this->evaluate($card);

        self::assertCount(1, $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'work')->fires);
    }

    public function test_a_rule_runs_its_list_of_actions_in_order_and_fires_once(): void
    {
        $card = $this->boundCard([self::listRule('both', [self::listed('first'), self::listed('second')])]);

        $this->evaluate($card);
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(['first', 'second'], ListedTestAction::$log);
        self::assertTrue($this->ruleState($card, 'both')->truth);
    }

    public function test_an_action_that_changes_the_facts_gives_the_next_action_of_the_list_the_facts_as_they_are_then(): void
    {
        $card = $this->boundCard([self::listRule('both', [self::listed('first'), self::listed('second')])]);

        $this->evaluate($card);

        self::assertCount(2, ListedTestAction::$facts);
        self::assertNotSame(ListedTestAction::$facts[0], ListedTestAction::$facts[1]);
    }

    public function test_the_second_action_of_a_list_reads_the_change_that_the_first_action_wrote(): void
    {
        $card = $this->boundCard([self::listRule('both', [self::listed('first'), self::listed('second')], self::PROVIDED_READY)]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        ListedTestAction::$afterRun = fn (string $label) => 'first' === $label ? $this->provider()->facts = new ProvidedFacts(ready: true, version: 2) : null;

        $this->evaluate($card);

        self::assertSame(['first', 'second'], ListedTestAction::$log);
        self::assertSame(
            [1, 2],
            array_map(static fn (Facts $facts): int => $facts->get(ProvidedFacts::class)->version, ListedTestAction::$facts),
        );
    }

    public function test_a_rule_with_one_refreshing_action_builds_the_facts_once_after_the_action(): void
    {
        $card = $this->boundCard([self::listRule('single', [self::listed('only')], self::PROVIDED_READY)]);
        $this->provider()->facts = new ProvidedFacts(ready: false);
        $this->provider()->builds = 0;
        $this->evaluate($card);
        $idle = $this->provider()->builds;
        self::assertSame([], ListedTestAction::$log);

        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->provider()->builds = 0;
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(['only'], ListedTestAction::$log);
        self::assertSame($idle + 1, $this->provider()->builds);
    }

    public function test_a_list_stops_at_the_first_refusal_and_runs_again_from_the_start_when_due(): void
    {
        $card = $this->boundCard([self::listRule('refusing', [self::listed('first'), self::listed('refuse'), self::listed('never')])]);

        $this->evaluate($card);

        self::assertSame(['first', 'refuse'], ListedTestAction::$log);
        $state = $this->ruleState($card, 'refusing');
        self::assertSame([1, 'listed-refusal', '2026-10-02 12:10:00'], [$state->attempts, $state->lastRefusal, $state->dueAt?->format('Y-m-d H:i:s')]);

        $this->evaluate($card, '2026-10-02 12:10:00');

        self::assertSame(['first', 'refuse', 'first', 'refuse'], ListedTestAction::$log);
        self::assertSame(2, $this->ruleState($card, 'refusing')->attempts);
    }

    public function test_a_list_ends_with_a_move_and_the_rules_after_it_wait_for_the_next_pass(): void
    {
        $card = $this->boundCard([
            self::listRule('moving', [self::listed('before'), ['move' => ['to' => 'two']]]),
            self::listRule('later', [self::listed('later')]),
        ]);

        $this->evaluate($card);

        self::assertSame(['before'], ListedTestAction::$log);
        self::assertSame('in-progress', $this->em()->find(Card::class, $card->id)?->column->slug);
    }

    public function test_a_refused_action_backs_off_retries_when_due_or_when_the_facts_change_and_pauses_after_the_last_retry(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)]);

        $this->evaluate($card);
        $state = $this->ruleState($card, 'stuck');
        self::assertSame([1, 'workflow-slot-missing', '2026-10-02 12:10:00'], [$state->attempts, $state->lastRefusal, $state->dueAt?->format('Y-m-d H:i:s')]);
        self::assertSame(self::NOON, $state->lastRefusalAt?->format('Y-m-d H:i:s'));

        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertSame(1, $this->ruleState($card, 'stuck')->attempts);

        $this->evaluate($card, '2026-10-02 12:10:00');
        self::assertSame([2, '2026-10-02 13:10:00'], [$this->ruleState($card, 'stuck')->attempts, $this->ruleState($card, 'stuck')->dueAt?->format('Y-m-d H:i:s')]);
        self::assertSame('2026-10-02 12:10:00', $this->ruleState($card, 'stuck')->lastRefusalAt?->format('Y-m-d H:i:s'));

        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 12:11:00');

        $state = $this->ruleState($card, 'stuck');
        self::assertSame([3, null], [$state->attempts, $state->dueAt]);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([PauseKind::Retries, 'workflow-slot-missing', 'stuck'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertCount(1, $this->paused);
        self::assertSame([PauseKind::Retries, 'workflow-slot-missing', $card->id?->toRfc4122(), $card->project->id?->toRfc4122()], [
            $this->paused[0]->kind,
            $this->paused[0]->reason,
            $this->paused[0]->cardId->toRfc4122(),
            $this->paused[0]->projectId->toRfc4122(),
        ]);
    }

    public function test_a_rule_state_that_stores_the_legacy_hash_waits_for_its_backoff_and_then_stores_the_provider_hash(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)]);
        $this->evaluate($card);
        $legacy = hash('sha256', '{"card-type":"feature"}');
        $this->ruleState($card, 'stuck')->fingerprint = $legacy;
        $this->em()->flush();

        $this->evaluate($card, '2026-10-02 12:05:00');

        $state = $this->ruleState($card, 'stuck');
        self::assertSame(1, $state->attempts, 'The deploy does not retry the rule early.');
        self::assertNotSame($legacy, $state->fingerprint);
        self::assertSame(hash('sha256', json_encode(['class:'.CardTypeFacts::class => 'feature'], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)), $state->fingerprint);
    }

    public function test_a_rule_state_that_stores_a_legacy_hash_of_other_facts_retries_at_once(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)]);
        $this->evaluate($card);
        $this->ruleState($card, 'stuck')->fingerprint = hash('sha256', '{"card-type":"epic"}');
        $this->em()->flush();

        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(2, $this->ruleState($card, 'stuck')->attempts);
    }

    public function test_a_refused_request_is_retried_when_due_and_the_card_pauses_when_the_retries_run_out(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]);
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];

        $this->refuse($first, 'failed', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $state = $this->ruleState($card, 'work');
        self::assertSame([1, 'failed', '2026-10-02 12:31:00', 0], [$state->attempts, $state->lastRefusal, $state->dueAt?->format('Y-m-d H:i:s'), $state->fires]);
        self::assertNull($this->activePause($card));
        self::assertSame([], $this->liveRequests($card));

        $this->evaluate($card, '2026-10-02 12:25:00');
        self::assertSame([], $this->liveRequests($card), 'The retry waits for its backoff.');

        $this->evaluate($card, '2026-10-02 12:31:00');
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertNotSame($first, $live[0]);
        $state = $this->ruleState($card, 'work');
        self::assertSame([1, 1, $live[0]->id?->toRfc4122()], [$state->attempts, $state->fires, $state->workRequestId?->toRfc4122()]);

        $this->evaluate($card, '2026-10-02 12:32:00');
        self::assertSame(1, $this->ruleState($card, 'work')->attempts, 'The evaluation of a live retry counts nothing.');

        $this->refuse($live[0], 'failed', '2026-10-02 12:40:00');
        $this->evaluate($card, '2026-10-02 12:41:00');

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([PauseKind::Retries, 'failed', 'work'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertSame(2, $this->ruleState($card, 'work')->attempts);
        self::assertSame([], $this->liveRequests($card), 'A policy with no repair opens no repair request.');

        $this->evaluate($card, '2026-10-02 12:50:00');
        self::assertCount(1, $this->paused, 'A counted refusal pauses the card once.');
    }

    public function test_a_refusal_code_that_the_template_does_not_retry_pauses_the_card_at_once(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]);
        $this->evaluate($card);

        $this->refuse($this->liveRequests($card)[0], 'needs-person', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([PauseKind::WorkStopped, 'needs-person', 'work'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertSame(0, $this->ruleState($card, 'work')->attempts);
    }

    public function test_a_work_stopped_pause_that_a_person_releases_does_not_pause_the_card_again_on_the_old_refusal(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'needs-person', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        self::assertNotNull($this->activePause($card));

        $this->releaseByPerson($card);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($this->activePause($card));
        self::assertCount(1, $this->liveRequests($card), 'The rule fired a new request with a fresh budget.');
    }

    public function test_a_work_stopped_pause_ends_when_a_resumed_run_reports_and_the_rule_requests_no_second_worker(): void
    {
        $card = $this->stoppedCard('needs-person');
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->resumedRun($card, WorkerRunState::Queued);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('run-resumed', $pause->releaseReason);
        self::assertNull($this->activePause($card));
        self::assertSame([], $this->liveRequests($card), 'The resumed run is the worker of the card.');
        $state = $this->ruleState($card, 'work');
        self::assertSame([true, 0, null], [$state->truth, $state->attempts, $state->lastRefusal]);

        $this->evaluate($card, '2026-10-02 12:40:00');
        self::assertSame([], $this->liveRequests($card));
        self::assertCount(1, $this->paused);
    }

    public function test_a_resume_release_leaves_a_system_entry_in_the_card_history(): void
    {
        $card = $this->stoppedCard('needs-person');
        $this->resumedRun($card, WorkerRunState::Running);
        $this->evaluate($card, '2026-10-02 12:30:00');

        $events = $this->service(CardEventRepository::class)->findBy(['card' => $card, 'kind' => CardEventKind::PauseReleased]);
        self::assertCount(1, $events);
        self::assertSame(Actor::System, $events[0]->actorKind);
        self::assertSame(['kind' => 'work-stopped', 'reason' => 'needs-person', 'ruleId' => 'work', 'releaseReason' => 'run-resumed'], $events[0]->detail);
    }

    public function test_a_release_on_changed_facts_leaves_a_system_entry_in_the_card_history(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)], backoffMinutes: []);
        $this->evaluate($card);
        self::assertNotNull($this->activePause($card));

        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 13:01:00');

        $events = $this->service(CardEventRepository::class)->findBy(['card' => $card, 'kind' => CardEventKind::PauseReleased]);
        self::assertCount(1, $events);
        self::assertSame(Actor::System, $events[0]->actorKind);
        self::assertNull($events[0]->actorUser);
        self::assertSame('facts-changed', $events[0]->detail['releaseReason'] ?? null);
        self::assertSame('stuck', $events[0]->detail['ruleId'] ?? null);
    }

    public function test_a_release_rule_leaves_a_system_entry_in_the_card_history(): void
    {
        $card = $this->boundCard(self::holdRules());
        $this->evaluate($card);
        self::assertNotNull($this->activePause($card));

        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 12:30:00');

        $events = $this->service(CardEventRepository::class)->findBy(['card' => $card, 'kind' => CardEventKind::PauseReleased]);
        self::assertCount(1, $events);
        self::assertSame(Actor::System, $events[0]->actorKind);
        self::assertSame(['kind' => 'rule', 'reason' => 'on-hold', 'ruleId' => 'hold', 'releaseReason' => ReleasePause::RELEASE_REASON], $events[0]->detail);
    }

    public function test_a_pause_that_holds_leaves_no_entry_in_the_card_history(): void
    {
        $card = $this->boundCard(self::holdRules());
        $this->evaluate($card);
        self::assertNotNull($this->activePause($card));

        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame([], $this->service(CardEventRepository::class)->findBy(['card' => $card, 'kind' => CardEventKind::PauseReleased]));
    }

    public function test_a_retries_pause_ends_when_a_person_resumes_the_run(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 0, 'backoffMinutes' => []]);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $pause = $this->activePause($card);
        self::assertSame(PauseKind::Retries, $pause?->kind);

        $this->resumedRun($card, WorkerRunState::Resumed);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('run-resumed', $pause->releaseReason);
        self::assertSame([], $this->liveRequests($card));
    }

    public function test_a_work_limit_pause_ends_when_a_resumed_run_reports(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['card.type' => ['types' => ['bug']]], limit: 1)]);
        $this->setType($card, 'bug');
        $this->evaluate($card);
        $this->setType($card, 'feature');
        $this->evaluate($card);
        $this->setType($card, 'bug');
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertSame(PauseKind::WorkLimit, $pause?->kind);

        $this->resumedRun($card, WorkerRunState::Running);
        $this->evaluate($card, '2026-10-02 13:00:00');

        self::assertSame('run-resumed', $pause->releaseReason);
    }

    public function test_a_pause_stays_while_no_run_since_it_began_continues_an_earlier_one(): void
    {
        $card = $this->stoppedCard('needs-person');
        $pause = $this->activePause($card);

        $this->workerRun($card, 'work', WorkerRunState::Running);
        $this->resumedRun($card, WorkerRunState::Running, new \DateTimeImmutable('2026-10-02 11:00:00'));
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($pause?->releasedAt, 'A fresh run and a resume from before the pause do not end it.');
    }

    public function test_a_pause_stays_when_a_person_stopped_the_resumed_run(): void
    {
        $card = $this->stoppedCard('needs-person');
        $pause = $this->activePause($card);

        $this->resumedRun($card, WorkerRunState::Stopped);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($pause?->releasedAt);
    }

    public function test_a_pause_that_does_not_wait_for_a_person_stays_when_the_resumed_run_belongs_to_another_rule(): void
    {
        $card = $this->stoppedCard('blocked');
        $pause = $this->activePause($card);

        $this->resumedRun($card, WorkerRunState::Running, ruleId: 'other');
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($pause?->releasedAt);
    }

    public function test_a_pause_stays_while_a_person_stops_the_resumed_run(): void
    {
        $card = $this->stoppedCard('needs-person');
        $pause = $this->activePause($card);

        $this->resumedRun($card, WorkerRunState::Stopping);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($pause?->releasedAt);
    }

    public function test_a_resumed_run_that_ended_before_the_evaluation_still_ends_the_pause_and_counts(): void
    {
        $card = $this->stoppedCard('needs-person', ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]);
        $pause = $this->activePause($card);

        $this->resumedRun($card, WorkerRunState::Failed);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('run-resumed', $pause?->releaseReason);
        self::assertNull($this->activePause($card));
        self::assertSame([1, 'failed'], [$this->ruleState($card, 'work')->attempts, $this->ruleState($card, 'work')->lastRefusal]);
    }

    public function test_an_interactive_run_that_reports_later_does_not_hide_the_resumed_run(): void
    {
        $card = $this->stoppedCard('needs-person');
        $pause = $this->activePause($card);

        $this->resumedRun($card, WorkerRunState::Running);
        $this->workerRun($card, 'work', WorkerRunState::Running);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('run-resumed', $pause?->releaseReason);
    }

    public function test_a_rule_pause_stays_when_a_run_resumes(): void
    {
        $card = $this->boundCard([[
            'id' => 'hold',
            'slot' => 'one',
            'when' => self::ALWAYS,
            'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['card.type' => ['types' => ['bug']]]]],
        ]]);
        $this->evaluate($card);
        $pause = $this->activePause($card);

        $this->resumedRun($card, WorkerRunState::Running);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($pause?->releasedAt);
    }

    public function test_a_resumed_run_that_fails_earns_a_retry_with_a_fresh_budget_and_counts_once(): void
    {
        $card = $this->stoppedCard('needs-person', ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]);
        $run = $this->resumedRun($card, WorkerRunState::Running);
        $this->evaluate($card, '2026-10-02 12:30:00');
        self::assertNull($this->activePause($card));

        $run->state = WorkerRunState::Failed;
        $run->endedAt = new \DateTimeImmutable('2026-10-02 12:35:00');
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:36:00');

        $state = $this->ruleState($card, 'work');
        self::assertSame([1, 'failed', '2026-10-02 12:46:00'], [$state->attempts, $state->lastRefusal, $state->dueAt?->format('Y-m-d H:i:s')]);
        self::assertNull($this->activePause($card));

        $this->evaluate($card, '2026-10-02 12:40:00');
        self::assertSame(1, $this->ruleState($card, 'work')->attempts, 'The same run counts once.');
        self::assertSame([], $this->liveRequests($card));

        $this->evaluate($card, '2026-10-02 12:46:00');
        self::assertCount(1, $this->liveRequests($card), 'The retry opens its request when it is due.');

        $this->finish($this->liveRequests($card)[0], '2026-10-02 12:50:00');
        $this->evaluate($card, '2026-10-02 12:51:00');
        self::assertSame(0, $this->ruleState($card, 'work')->attempts, 'A done retry does not count the old run again.');
        self::assertNull($this->activePause($card));
    }

    public function test_a_resumed_run_that_blocks_pauses_the_card_at_once(): void
    {
        $card = $this->stoppedCard('blocked');
        $run = $this->resumedRun($card, WorkerRunState::Running);
        $this->evaluate($card, '2026-10-02 12:30:00');

        $run->state = WorkerRunState::Blocked;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:31:00');

        $pause = $this->activePause($card);
        self::assertSame([PauseKind::WorkStopped, 'blocked'], [$pause?->kind, $pause?->reason]);
    }

    public function test_a_resumed_run_that_succeeds_counts_nothing(): void
    {
        $card = $this->stoppedCard('needs-person');
        $run = $this->resumedRun($card, WorkerRunState::Running);
        $this->evaluate($card, '2026-10-02 12:30:00');

        $run->state = WorkerRunState::Succeeded;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:35:00');

        self::assertSame([0, null], [$this->ruleState($card, 'work')->attempts, $this->ruleState($card, 'work')->lastRefusal]);
        self::assertNull($this->activePause($card));
        self::assertSame([], $this->liveRequests($card));
    }

    public function test_a_needs_person_stop_that_settles_during_another_pause_does_not_pause_the_card_when_the_resumed_worker_reports(): void
    {
        $card = $this->twoRuleCard(stopOfRuleA: 'blocked');
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame('a', $pause->ruleId);

        $run = $this->resumedRun($card, WorkerRunState::Running, ruleId: 'a');
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('run-resumed', $pause->releaseReason);
        self::assertNull($this->activePause($card));
        self::assertSame([], $this->liveRequests($card));
        $state = $this->ruleState($card, 'b');
        self::assertSame([true, 0, null, $run->id?->toRfc4122()], [$state->truth, $state->attempts, $state->lastRefusal, $state->answeringRunId?->toRfc4122()]);
    }

    public function test_a_needs_person_stop_does_not_pause_the_card_when_a_resumed_worker_reported_before_the_pause_would_form(): void
    {
        $card = $this->twoRuleCard();
        $run = $this->resumedRun($card, WorkerRunState::Running, ruleId: 'a');
        $this->evaluate($card, '2026-10-02 12:21:00');

        self::assertNull($this->activePause($card));
        self::assertSame([], $this->paused);
        self::assertSame($run->id?->toRfc4122(), $this->ruleState($card, 'b')->answeringRunId?->toRfc4122());
    }

    public function test_a_needs_person_stop_that_settles_after_the_resume_still_pauses_the_card(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::RETRY_ONCE);
        $this->evaluate($card);
        $this->resumedRun($card, WorkerRunState::Running, new \DateTimeImmutable('2026-10-02 12:10:00'));
        $this->refuse($this->liveRequests($card)[0], 'needs-person', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');

        $pause = $this->activePause($card);
        self::assertSame([PauseKind::WorkStopped, 'needs-person'], [$pause?->kind, $pause?->reason]);
        self::assertNull($this->ruleState($card, 'work')->answeringRunId);
    }

    public function test_the_answered_stop_costs_no_retry_and_a_failed_end_of_the_resumed_worker_earns_the_first_retry(): void
    {
        $card = $this->twoRuleCard();
        $run = $this->resumedRun($card, WorkerRunState::Running, ruleId: 'a');
        $this->evaluate($card, '2026-10-02 12:21:00');

        $state = $this->ruleState($card, 'b');
        self::assertSame([0, null], [$state->attempts, $state->dueAt]);

        $run->state = WorkerRunState::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:31:00');

        $state = $this->ruleState($card, 'b');
        self::assertSame([1, 'failed', '2026-10-02 12:41:00', null], [$state->attempts, $state->lastRefusal, $state->dueAt?->format('Y-m-d H:i:s'), $state->answeringRunId]);
        self::assertNull($this->activePause($card));
    }

    public function test_a_resumed_worker_of_another_rule_that_ends_a_needs_person_pause_and_then_fails_earns_a_retry(): void
    {
        $card = $this->twoRuleCard(stopOfRuleB: false);
        $this->refuse($this->ruleRequest($card, 'b'), 'needs-person', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame(['b', 'needs-person'], [$pause->ruleId, $pause->reason]);

        $run = $this->resumedRun($card, WorkerRunState::Running, ruleId: 'a');
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('run-resumed', $pause->releaseReason);
        self::assertNull($this->activePause($card));
        self::assertSame($run->id?->toRfc4122(), $this->ruleState($card, 'b')->answeringRunId?->toRfc4122());

        $run->state = WorkerRunState::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:36:00');

        $state = $this->ruleState($card, 'b');
        self::assertSame([1, 'failed', '2026-10-02 12:46:00'], [$state->attempts, $state->lastRefusal, $state->dueAt?->format('Y-m-d H:i:s')]);
        self::assertNull($this->activePause($card));
    }

    public function test_a_resumed_worker_that_kept_a_pause_from_forming_and_then_blocks_pauses_the_card(): void
    {
        $card = $this->twoRuleCard();
        $run = $this->resumedRun($card, WorkerRunState::Running, ruleId: 'a');
        $this->evaluate($card, '2026-10-02 12:21:00');
        self::assertNull($this->activePause($card));

        $run->state = WorkerRunState::Blocked;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:31:00');

        $pause = $this->activePause($card);
        self::assertSame([PauseKind::WorkStopped, 'blocked', 'b'], [$pause?->kind, $pause?->reason, $pause?->ruleId]);
        self::assertNull($this->ruleState($card, 'b')->answeringRunId);
    }

    public function test_a_resumed_worker_that_kept_a_pause_from_forming_and_then_never_starts_pauses_the_card(): void
    {
        $card = $this->twoRuleCard();
        $run = $this->resumedRun($card, WorkerRunState::Queued, ruleId: 'a');
        $this->evaluate($card, '2026-10-02 12:21:00');
        self::assertNull($this->activePause($card));

        $run->state = WorkerRunState::NotStarted;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:31:00');

        $pause = $this->activePause($card);
        self::assertSame([PauseKind::WorkStopped, 'not-started', 'b'], [$pause?->kind, $pause?->reason, $pause?->ruleId]);
    }

    public function test_a_resumed_worker_that_ends_well_clears_the_stored_run_and_pauses_nothing(): void
    {
        $card = $this->twoRuleCard();
        $run = $this->resumedRun($card, WorkerRunState::Running, ruleId: 'a');
        $this->evaluate($card, '2026-10-02 12:21:00');
        self::assertNotNull($this->ruleState($card, 'b')->answeringRunId);

        $run->state = WorkerRunState::Succeeded;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:31:00');

        $state = $this->ruleState($card, 'b');
        self::assertSame([null, 0, null], [$state->answeringRunId, $state->attempts, $state->lastRefusal]);
        self::assertNull($this->activePause($card));
        self::assertSame([], $this->liveRequests($card));
    }

    public function test_the_failure_of_a_resumed_worker_does_not_count_after_the_rule_turned_false_and_true_again(): void
    {
        $card = $this->twoRuleCard(ruleBWhen: ['card.type' => ['types' => ['bug']]]);
        $this->setType($card, 'bug');
        $run = $this->resumedRun($card, WorkerRunState::Running, ruleId: 'a');
        $this->evaluate($card, '2026-10-02 12:21:00');
        self::assertNotNull($this->ruleState($card, 'b')->answeringRunId);

        $this->setType($card, 'feature');
        $this->evaluate($card, '2026-10-02 12:25:00');
        self::assertNull($this->ruleState($card, 'b')->answeringRunId);
        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 12:26:00');
        self::assertSame(['b'], array_map(static fn (WorkRequest $request): string => $request->ruleId, $this->liveRequests($card)));

        $run->state = WorkerRunState::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:31:00');

        $state = $this->ruleState($card, 'b');
        self::assertSame([0, null], [$state->attempts, $state->lastRefusal]);
        self::assertNull($this->activePause($card));
    }

    public function test_the_failure_of_a_resumed_worker_does_not_count_against_the_request_of_a_new_subject(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 3)], onWorkFailed: self::RETRY_ONCE);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'needs-person', '2026-10-02 12:20:00');
        $run = $this->workerRun($card, 'fix', WorkerRunState::Running, $this->workerRun($card, 'fix', WorkerRunState::Blocked));
        $this->evaluate($card, '2026-10-02 12:21:00');
        self::assertSame($run->id?->toRfc4122(), $this->ruleState($card, 'fix')->answeringRunId?->toRfc4122());

        $base->checks = PullRequestChecks::Passed;
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');
        self::assertCount(1, $this->liveRequests($card));
        self::assertNull($this->ruleState($card, 'fix')->answeringRunId);

        $run->state = WorkerRunState::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:40:00');

        $state = $this->ruleState($card, 'fix');
        self::assertSame([0, null], [$state->attempts, $state->lastRefusal]);
        self::assertNull($this->activePause($card));
    }

    public function test_a_resumed_worker_that_blocks_while_the_subject_changes_does_not_pause_the_request_of_the_new_subject(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 3)], onWorkFailed: self::RETRY_ONCE);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'needs-person', '2026-10-02 12:20:00');
        $run = $this->workerRun($card, 'fix', WorkerRunState::Running, $this->workerRun($card, 'fix', WorkerRunState::Blocked));
        $this->evaluate($card, '2026-10-02 12:21:00');
        self::assertSame($run->id?->toRfc4122(), $this->ruleState($card, 'fix')->answeringRunId?->toRfc4122());

        $run->state = WorkerRunState::Blocked;
        $base->checks = PullRequestChecks::Passed;
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($this->activePause($card));
        self::assertCount(1, $this->liveRequests($card));
        $state = $this->ruleState($card, 'fix');
        self::assertSame([null, 0, null, $upper->id?->toRfc4122()], [$state->answeringRunId, $state->attempts, $state->lastRefusal, $state->subjectPullRequestId?->toRfc4122()]);
    }

    public function test_a_rule_that_leaves_its_slot_forgets_its_answering_run(): void
    {
        $card = $this->twoRuleCard();
        $run = $this->resumedRun($card, WorkerRunState::Running, ruleId: 'a');
        $this->evaluate($card, '2026-10-02 12:21:00');
        self::assertNotNull($this->ruleState($card, 'b')->answeringRunId);

        $this->moveTo($card, 'in-progress');
        $this->evaluate($card, '2026-10-02 12:25:00');
        self::assertNull($this->ruleState($card, 'b')->answeringRunId);

        $run->state = WorkerRunState::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:31:00');

        self::assertSame(0, $this->ruleState($card, 'b')->attempts);
        self::assertNull($this->activePause($card));
    }

    #[DataProvider('stopsThatWaitForNoAnswer')]
    public function test_a_stop_that_does_not_wait_for_a_person_pauses_the_card_although_a_resumed_worker_reported(string $code): void
    {
        $card = $this->twoRuleCard(stopOfRuleB: false);
        $this->resumedRun($card, WorkerRunState::Running, ruleId: 'a');
        $this->refuse($this->ruleRequest($card, 'b'), $code, '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');

        $pause = $this->activePause($card);
        self::assertSame([PauseKind::WorkStopped, $code, 'b'], [$pause?->kind, $pause?->reason, $pause?->ruleId]);
        self::assertNull($this->ruleState($card, 'b')->answeringRunId);
    }

    /** @return iterable<string, array{string}> */
    public static function stopsThatWaitForNoAnswer(): iterable
    {
        yield 'blocked' => ['blocked'];
        yield 'not-started' => ['not-started'];
    }

    public function test_the_retries_of_a_failed_resumed_worker_end_in_a_repair_and_a_retries_pause_at_the_same_count_as_a_rule_worker(): void
    {
        $card = $this->twoRuleCard(onWorkFailed: self::REPAIR);
        $run = $this->resumedRun($card, WorkerRunState::Running, ruleId: 'a');
        $this->evaluate($card, '2026-10-02 12:21:00');

        $run->state = WorkerRunState::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:31:00');
        self::assertSame(1, $this->ruleState($card, 'b')->attempts);

        $this->evaluate($card, '2026-10-02 12:41:00');
        $retry = $this->liveRequestOfKind($card, 'work');
        self::assertSame(1, $this->ruleState($card, 'b')->attempts, 'The live retry keeps the attempts.');

        $this->refuse($retry, 'failed', '2026-10-02 12:45:00');
        $this->evaluate($card, '2026-10-02 12:46:00');
        $repair = $this->liveRequestOfKind($card, 'repair');
        $state = $this->ruleState($card, 'b');
        self::assertSame([2, true], [$state->attempts, $state->repaired]);
        self::assertNull($this->activePause($card));

        $this->refuse($repair, 'failed', '2026-10-02 12:50:00');
        $this->evaluate($card, '2026-10-02 12:51:00');
        self::assertSame(PauseKind::Retries, $this->activePause($card)?->kind);
    }

    public function test_a_refusal_that_settles_while_another_pause_holds_the_card_is_counted_after_it_ends(): void
    {
        $card = $this->boundCard([
            [
                'id' => 'hold',
                'slot' => 'one',
                'when' => ['card.type' => ['types' => ['bug']]],
                'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['card.type' => ['types' => ['security']]]]],
            ],
            self::requestRule('work', self::ALWAYS),
        ], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]);
        $this->evaluate($card);
        $request = $this->liveRequests($card)[0];
        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertSame(PauseKind::Rule, $this->activePause($card)?->kind);

        $this->refuse($request, 'needs-person', '2026-10-02 12:10:00');
        $this->evaluate($card, '2026-10-02 12:11:00');
        self::assertNull($this->ruleState($card, 'work')->lastRefusal, 'The refusal waits while the rule pause holds the card.');

        $this->setType($card, 'security');
        $this->evaluate($card, '2026-10-02 12:20:00');
        self::assertSame(PauseKind::WorkStopped, $this->activePause($card)?->kind);
    }

    public function test_releasing_a_hold_clears_the_tracked_request(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'needs-person', '2026-10-02 12:20:00');
        $this->hold($card);
        $this->releaseHold($card, '2026-10-02 12:30:00');

        self::assertNull($this->ruleState($card, 'work')->workRequestId);
    }

    public function test_a_done_request_clears_the_attempts_of_a_retry(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 2, 'backoffMinutes' => [10, 60]]);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $this->evaluate($card, '2026-10-02 12:31:00');

        $this->finish($this->liveRequests($card)[0]);
        $this->evaluate($card, '2026-10-02 12:40:00');

        $state = $this->ruleState($card, 'work');
        self::assertSame([0, null, null], [$state->attempts, $state->workRequestId, $state->dueAt]);
        self::assertNull($this->activePause($card));
    }

    public function test_a_done_request_of_a_rule_that_opts_in_counts_as_a_failed_try_while_the_rule_holds(): void
    {
        $rule = self::requestRule('work', self::ALWAYS);
        $rule['then']['request']['stillTrue'] = 'retry';
        $card = $this->boundCard([$rule], onWorkFailed: ['retryOn' => ['still-true'], 'retries' => 2, 'backoffMinutes' => [2, 3]]);
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];

        $this->finish($first, '2026-10-02 12:00:30');
        $this->evaluate($card, '2026-10-02 12:01:00');
        $state = $this->ruleState($card, 'work');
        self::assertSame([1, 'still-true', '2026-10-02 12:03:00'], [$state->attempts, $state->lastRefusal, $state->dueAt?->format('Y-m-d H:i:s')]);
        self::assertSame([], $this->liveRequests($card));

        $this->evaluate($card, '2026-10-02 12:02:00');
        self::assertSame(1, $this->ruleState($card, 'work')->attempts, 'One settle counts once.');

        $this->evaluate($card, '2026-10-02 12:03:00');
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertNotSame($first, $live[0]);
    }

    public function test_a_done_request_whose_facts_changed_while_it_ran_still_waits_for_the_first_retry(): void
    {
        $rule = self::requestRule('work', self::NOT_EPIC);
        $rule['then']['request']['stillTrue'] = 'retry';
        $card = $this->boundCard([$rule], onWorkFailed: ['retryOn' => ['still-true'], 'retries' => 2, 'backoffMinutes' => [2, 3]]);
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];

        $this->setType($card, 'bug');
        $this->finish($first, '2026-10-02 12:00:30');
        $this->evaluate($card, '2026-10-02 12:01:00');
        $state = $this->ruleState($card, 'work');
        self::assertSame([1, '2026-10-02 12:03:00'], [$state->attempts, $state->dueAt?->format('Y-m-d H:i:s')]);
        self::assertSame([], $this->liveRequests($card), 'The change and the settle in one pass keep the wait.');

        $this->evaluate($card, '2026-10-02 12:03:00');
        self::assertCount(1, $this->liveRequests($card));
    }

    public function test_a_done_request_that_keeps_the_rule_true_pauses_the_card_when_the_retries_run_out(): void
    {
        $rule = self::requestRule('work', self::ALWAYS);
        $rule['then']['request']['stillTrue'] = 'retry';
        $card = $this->boundCard([$rule], onWorkFailed: ['retryOn' => ['still-true'], 'retries' => 1, 'backoffMinutes' => [2, 3]]);
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0], '2026-10-02 12:00:30');
        $this->evaluate($card, '2026-10-02 12:01:00');
        $this->evaluate($card, '2026-10-02 12:03:00');
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        $this->finish($live[0], '2026-10-02 12:03:30');
        $this->evaluate($card, '2026-10-02 12:04:00');

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([PauseKind::Retries, 'still-true', 'work'], [$pause->kind, $pause->reason, $pause->ruleId]);
    }

    public function test_a_retry_does_not_use_a_request_of_the_work_limit(): void
    {
        $card = $this->boundCard([self::requestRule('fix', self::ALWAYS, limit: 1)], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 2, 'backoffMinutes' => [10, 60]]);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $this->evaluate($card, '2026-10-02 12:31:00');

        self::assertNull($this->activePause($card));
        self::assertCount(1, $this->liveRequests($card));
    }

    public function test_a_retry_waits_for_the_delay_of_the_failure_block_and_the_card_pauses_after_the_last_retry(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed', 'timeout'], 'retries' => 3, 'backoffMinutes' => [2, 3, 5]]);
        $this->evaluate($card);

        foreach (['12:12:00' => '12:10:00', '12:23:00' => '12:20:00', '12:35:00' => '12:30:00'] as $due => $refused) {
            self::assertNull($this->activePause($card));
            $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 '.$refused);
            $this->evaluate($card, '2026-10-02 '.$refused);
            self::assertSame('2026-10-02 '.$due, $this->ruleState($card, 'work')->dueAt?->format('Y-m-d H:i:s'));
            $this->evaluate($card, '2026-10-02 '.$due);
        }

        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:40:00');
        $this->evaluate($card, '2026-10-02 12:40:00');

        self::assertSame(PauseKind::Retries, $this->activePause($card)?->kind);
        self::assertSame(4, $this->ruleState($card, 'work')->attempts);
    }

    public function test_a_refusal_code_outside_the_retry_list_pauses_the_card_with_no_retry(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed', 'timeout'], 'retries' => 3, 'backoffMinutes' => [2, 3, 5]]);
        $this->evaluate($card);

        $this->refuse($this->liveRequests($card)[0], 'unfinished', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');

        $pause = $this->activePause($card);
        self::assertSame([PauseKind::WorkStopped, 'unfinished'], [$pause?->kind, $pause?->reason]);
        self::assertSame(0, $this->ruleState($card, 'work')->attempts);
    }

    public function test_a_template_with_no_failure_block_ignores_a_refused_request(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:20:00');

        $this->evaluate($card, '2026-10-02 12:21:00');

        self::assertNull($this->activePause($card));
        self::assertSame(0, $this->ruleState($card, 'work')->attempts);
    }

    public function test_a_rule_whose_retries_run_out_opens_a_repair_request_instead_of_a_pause(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);

        $repair = $this->repairing($card);

        self::assertNull($this->activePause($card));
        self::assertSame([], $this->paused);
        self::assertSame(['repair', 'work', null, 'failed'], [$repair->kind, $repair->ruleId, $repair->capability, $repair->context->reason]);
        $state = $this->ruleState($card, 'work');
        self::assertSame([2, true, null, $repair->id?->toRfc4122()], [$state->attempts, $state->repaired, $state->dueAt, $state->workRequestId?->toRfc4122()]);
    }

    public function test_the_repair_of_an_app_rule_carries_the_repair_prompt_and_not_the_prompt_of_the_rule(): void
    {
        $card = $this->boundCard([], onWorkFailed: self::REPAIR);
        $this->appRules = new AppRules($this->service(TemplateParser::class), AppRulesTest::FIXTURE);
        $this->moveTo($card, 'backlog');

        $repair = $this->repairing($card);

        self::assertSame(['repair', 'app-groom'], [$repair->kind, $repair->ruleId]);
        self::assertSame($this->service(KindPrompts::class)->forKind('repair'), $repair->prompt);
        self::assertStringContainsString('Use the loupe-stage-repair skill.', $repair->prompt ?? '');
    }

    public function test_a_repair_request_that_cannot_open_pauses_the_card(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $this->evaluate($card);
        $this->refuse($this->liveRequestOfKind($card, 'work'), 'failed', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $this->evaluate($card, '2026-10-02 12:31:00');
        // A live request of the repair kind makes the open refuse.
        $this->em()->persist(new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, 'repair', null, 'work', new \DateTimeImmutable(self::NOON)));
        $this->em()->flush();

        $this->refuse($this->liveRequestOfKind($card, 'work'), 'failed', '2026-10-02 12:40:00');
        $this->evaluate($card, '2026-10-02 12:41:00');

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([PauseKind::Retries, 'failed', 'work'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertFalse($this->ruleState($card, 'work')->repaired);
    }

    public function test_a_done_repair_fires_the_rule_once_more_in_the_same_pass(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);

        $this->finish($repair, '2026-10-02 12:50:00');
        $this->evaluate($card, '2026-10-02 12:51:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('work', $live[0]->kind);
        $state = $this->ruleState($card, 'work');
        self::assertSame([2, true, $live[0]->id?->toRfc4122()], [$state->attempts, $state->repaired, $state->workRequestId?->toRfc4122()]);

        $this->evaluate($card, '2026-10-02 12:52:00');
        self::assertSame($live, $this->liveRequests($card), 'The read repair does not fire the rule again.');
    }

    public function test_a_last_try_that_finds_its_work_live_fires_once(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);
        $this->finish($repair, '2026-10-02 12:50:00');
        $this->em()->persist(new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, 'work', null, 'work', new \DateTimeImmutable(self::NOON)));
        $this->em()->flush();

        $this->evaluate($card, '2026-10-02 12:51:00');
        self::assertSame(['work'], $this->firedRules());
        $records = \count($this->firedRecords());

        $this->evaluate($card, '2026-10-02 12:52:00');
        self::assertCount($records, $this->firedRecords(), 'The read repair does not fire the rule again.');
        self::assertNull($this->activePause($card));
    }

    public function test_a_last_try_whose_action_refuses_pauses_the_card_with_no_further_try(): void
    {
        $rule = ['id' => 'work', 'slot' => 'one', 'when' => self::ALWAYS, 'then' => ['request' => ['kind' => 'work', 'document' => ['tag' => 'missing']]]];
        $card = $this->boundCard([$rule], backoffMinutes: [10, 60, 360], onWorkFailed: self::REPAIR);
        $this->evaluate($card);
        $state = $this->ruleState($card, 'work');
        $state->attempts = 2;
        $state->repaired = true;
        $state->dueAt = new \DateTimeImmutable('2026-10-02 12:50:00');
        $this->em()->flush();

        $this->evaluate($card, '2026-10-02 12:51:00');

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([PauseKind::Retries, 'document-not-found'], [$pause->kind, $pause->reason]);
    }

    public function test_a_refused_repair_pauses_the_card_with_the_repair_failed_code(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);

        $this->refuse($repair, 'failed', '2026-10-02 12:50:00');
        $this->evaluate($card, '2026-10-02 12:51:00');

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([PauseKind::Retries, 'repair-failed', 'work'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertSame('repair-failed', $this->ruleState($card, 'work')->lastRefusal);
        self::assertSame([], $this->liveRequests($card));
    }

    public function test_a_failed_last_try_after_a_repair_pauses_the_card_with_its_own_code(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $this->finish($this->repairing($card), '2026-10-02 12:50:00');
        $this->evaluate($card, '2026-10-02 12:51:00');

        $this->refuse($this->liveRequests($card)[0], 'timeout', '2026-10-02 13:00:00');
        $this->evaluate($card, '2026-10-02 13:01:00');

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([PauseKind::Retries, 'timeout', 'work'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertSame([], $this->liveRequests($card), 'The card gets one repair.');
    }

    public function test_a_live_repair_holds_the_rule_when_its_facts_change(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::NOT_EPIC)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);
        $fingerprint = $this->ruleState($card, 'work')->fingerprint;

        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 12:45:00');

        self::assertSame([$repair], $this->liveRequests($card));
        self::assertNotSame($fingerprint, $this->ruleState($card, 'work')->fingerprint);
        self::assertNull($this->activePause($card));
    }

    public function test_a_rule_that_turns_false_cancels_its_live_repair(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::NOT_EPIC)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);

        $this->setType($card, 'epic');
        $this->evaluate($card, '2026-10-02 12:45:00');

        self::assertSame(WorkRequestState::Cancelled, $repair->state);
        self::assertFalse($this->ruleState($card, 'work')->repaired);
    }

    public function test_a_live_request_is_cancelled_when_its_cancel_when_holds(): void
    {
        $rule = self::requestRule('work', self::ALWAYS);
        $rule['then']['request']['cancelWhen'] = ['card.type' => ['types' => ['epic']]];
        $card = $this->boundCard([$rule]);
        $this->evaluate($card);
        $request = $this->liveRequestOfKind($card, 'work');

        $this->setType($card, 'epic');
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(WorkRequestState::Cancelled, $request->state);
    }

    public function test_a_live_request_stays_when_its_rule_turns_false_and_its_cancel_when_does_not_hold(): void
    {
        $rule = self::requestRule('work', self::NOT_EPIC);
        $rule['then']['request']['cancelWhen'] = ['card.type' => ['types' => ['bug']]];
        $card = $this->boundCard([$rule]);
        $this->evaluate($card);
        $request = $this->liveRequestOfKind($card, 'work');

        $this->setType($card, 'epic');
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame([$request], $this->liveRequests($card));
        self::assertSame(WorkRequestState::Open, $request->state);
    }

    public function test_an_open_request_is_cancelled_when_a_session_opens_and_its_rule_cancels_only_open_work(): void
    {
        $card = $this->boundCard([$this->sessionRule(true)]);
        $this->evaluate($card);
        $request = $this->liveRequestOfKind($card, 'work');

        $this->interactiveRun($card);
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(WorkRequestState::Cancelled, $request->state);
    }

    public function test_a_claimed_request_stays_when_a_session_opens_and_its_rule_cancels_only_open_work(): void
    {
        $card = $this->boundCard([$this->sessionRule(true)]);
        $this->evaluate($card);
        $request = $this->liveRequestOfKind($card, 'work');
        $request->state = WorkRequestState::Claimed;
        $this->em()->flush();

        $this->interactiveRun($card);
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(WorkRequestState::Claimed, $request->state);
        self::assertSame([$request], $this->liveRequests($card));
    }

    public function test_a_claimed_request_is_cancelled_when_a_session_opens_and_its_rule_cancels_all_work(): void
    {
        $card = $this->boundCard([$this->sessionRule(false)]);
        $this->evaluate($card);
        $request = $this->liveRequestOfKind($card, 'work');
        $request->state = WorkRequestState::Claimed;
        $this->em()->flush();

        $this->interactiveRun($card);
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(WorkRequestState::Cancelled, $request->state);
    }

    public function test_a_rule_that_waits_for_a_session_makes_no_request_while_one_is_open(): void
    {
        $card = $this->boundCard([$this->sessionRule(true)]);
        $run = $this->interactiveRun($card);

        $this->evaluate($card);

        self::assertSame([], $this->requestRuleIds($card));

        $run->state = WorkerRunState::Stopped;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(['work'], $this->requestRuleIds($card));
    }

    public function test_a_repair_that_no_bridge_takes_keeps_waiting(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);

        $this->evaluate($card, '2026-10-03 14:00:00');

        self::assertSame(WorkRequestState::Open, $repair->state);
        self::assertNull($this->activePause($card));
    }

    public function test_a_card_that_leaves_the_slot_cancels_its_repair(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);

        $this->moveTo($card, 'in-progress');
        $this->evaluate($card, '2026-10-02 12:45:00');

        self::assertSame(WorkRequestState::Cancelled, $repair->state);
        self::assertFalse($this->ruleState($card, 'work')->repaired);
    }

    public function test_a_refusal_code_with_no_retry_pauses_at_once_with_no_repair(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $this->evaluate($card);

        $this->refuse($this->liveRequests($card)[0], 'needs-person', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');

        self::assertSame(PauseKind::WorkStopped, $this->activePause($card)?->kind);
        self::assertSame([], $this->liveRequests($card));
        self::assertFalse($this->ruleState($card, 'work')->repaired);
    }

    public function test_a_retries_pause_stays_with_the_same_facts_and_releases_when_they_change_and_the_rule_fires_again(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)], backoffMinutes: []);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->evaluate($card, '2026-10-02 13:00:00');
        self::assertNull($pause->releasedAt);

        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 13:01:00');

        self::assertSame('facts-changed', $pause->releaseReason);
        $next = $this->activePause($card);
        self::assertNotNull($next, 'The rule fired again at once and its first refusal paused the card again.');
        self::assertNotSame($pause, $next);
        self::assertSame(1, $this->ruleState($card, 'stuck')->attempts);
    }

    public function test_a_work_stopped_pause_stays_when_the_rule_last_saw_its_request_open(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::IDLE)], onWorkFailed: self::RETRY_ONCE);
        $this->evaluate($card);
        $this->evaluate($card, '2026-10-02 12:05:00');
        $this->refuse($this->liveRequests($card)[0], 'needs-person', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $pause = $this->activePause($card);
        self::assertSame(PauseKind::WorkStopped, $pause?->kind);

        $this->evaluate($card, '2026-10-02 12:22:00');
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame($pause, $this->activePause($card));
        self::assertSame([], $this->liveRequests($card));
        self::assertCount(1, $this->requestRuleIds($card));

        $this->releaseByPerson($card);
        $this->evaluate($card, '2026-10-02 12:40:00');

        self::assertNull($this->activePause($card));
        self::assertCount(1, $this->liveRequests($card));
        self::assertCount(2, $this->requestRuleIds($card));
    }

    public function test_a_retry_waits_for_its_delay_when_the_rule_last_saw_its_request_open(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::IDLE)], onWorkFailed: self::RETRY_ONCE);
        $this->evaluate($card);
        $this->evaluate($card, '2026-10-02 12:05:00');
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:20:00');

        $this->evaluate($card, '2026-10-02 12:21:00');
        self::assertSame('2026-10-02 12:31:00', $this->ruleState($card, 'work')->dueAt?->format('Y-m-d H:i:s'));
        $this->evaluate($card, '2026-10-02 12:25:00');

        self::assertNull($this->activePause($card));
        self::assertSame([], $this->liveRequests($card), 'The retry waits for its backoff.');
        self::assertCount(1, $this->requestRuleIds($card));

        $this->evaluate($card, '2026-10-02 12:31:00');

        self::assertCount(1, $this->liveRequests($card));
        self::assertCount(2, $this->requestRuleIds($card));
    }

    public function test_a_repair_failed_pause_stays_when_the_rule_last_saw_the_repair_open(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::IDLE)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);
        $this->evaluate($card, '2026-10-02 12:45:00');
        $this->refuse($repair, 'failed', '2026-10-02 12:50:00');

        $this->evaluate($card, '2026-10-02 12:51:00');
        $pause = $this->activePause($card);
        self::assertSame([PauseKind::Retries, 'repair-failed'], [$pause?->kind, $pause?->reason]);

        $this->evaluate($card, '2026-10-02 12:52:00');
        $this->evaluate($card, '2026-10-02 13:00:00');

        self::assertSame($pause, $this->activePause($card));
        self::assertSame([], $this->liveRequests($card));
        self::assertCount(3, $this->requestRuleIds($card));
    }

    public function test_a_work_stopped_pause_ends_when_a_fact_the_rule_reads_changes_after_the_refusal(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::IDLE)], onWorkFailed: self::RETRY_ONCE);
        $this->evaluate($card);
        $this->evaluate($card, '2026-10-02 12:05:00');
        $this->refuse($this->liveRequests($card)[0], 'blocked', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('facts-changed', $pause->releaseReason);
        self::assertNull($this->activePause($card));
        self::assertCount(1, $this->liveRequests($card));
        self::assertCount(2, $this->requestRuleIds($card));
    }

    public function test_a_needs_person_pause_stays_when_a_fact_the_rule_reads_changes(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::IDLE)], onWorkFailed: self::RETRY_ONCE);
        $this->evaluate($card);
        $this->evaluate($card, '2026-10-02 12:05:00');
        $this->refuse($this->liveRequests($card)[0], 'needs-person', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($pause->releasedAt);
        self::assertSame([], $this->liveRequests($card));
    }

    public function test_a_rule_pause_releases_when_its_until_is_met(): void
    {
        $card = $this->boundCard([[
            'id' => 'hold',
            'slot' => 'one',
            'when' => self::ALWAYS,
            'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['card.type' => ['types' => ['bug']]]]],
        ]]);

        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([PauseKind::Rule, 'on-hold'], [$pause->kind, $pause->reason]);

        $this->evaluate($card, '2026-10-02 12:30:00');
        self::assertNull($pause->releasedAt);

        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 12:31:00');

        self::assertSame('until-met', $pause->releaseReason);
        self::assertNull($this->activePause($card), 'The pause rule stays true, so it does not fire again.');
    }

    public function test_the_request_limit_pauses_and_the_work_limit_pause_stays_until_the_card_leaves_the_slot(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['card.type' => ['types' => ['bug']]], limit: 1)]);
        $this->setType($card, 'bug');
        $this->evaluate($card);
        $this->setType($card, 'feature');
        $this->evaluate($card);
        $this->setType($card, 'bug');
        $this->evaluate($card);

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([PauseKind::WorkLimit, 'work-limit-reached', 'fix'], [$pause->kind, $pause->reason, $pause->ruleId]);

        $this->setType($card, 'security');
        $this->evaluate($card);
        self::assertNull($pause->releasedAt);

        $this->moveTo($card, 'in-progress');
        $this->evaluate($card);
        self::assertSame('left-slot', $pause->releaseReason);
    }

    public function test_a_healthy_pull_request_refills_the_fix_budget_so_routine_fixes_never_pause_the_card(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 2, refill: true)]);
        $pullRequest = $this->pullRequest($card);
        $minute = 0;
        for ($round = 1; $round <= 4; ++$round) {
            $pullRequest->checks = PullRequestChecks::Failed;
            $this->em()->flush();
            $this->evaluate($card, \sprintf('2026-10-02 12:%02d:00', ++$minute));
            $live = $this->liveRequests($card);
            self::assertCount(1, $live, 'Round '.$round);
            self::assertSame(1, $this->ruleState($card, 'fix')->fires);
            $this->finish($live[0]);

            $pullRequest->checks = PullRequestChecks::Pending;
            $this->em()->flush();
            $this->evaluate($card, \sprintf('2026-10-02 12:%02d:00', ++$minute));
            self::assertSame(1, $this->ruleState($card, 'fix')->fires, 'A push alone refills nothing.');

            $pullRequest->checks = PullRequestChecks::Passed;
            $this->em()->flush();
            $this->evaluate($card, \sprintf('2026-10-02 12:%02d:00', ++$minute));
            self::assertSame(0, $this->ruleState($card, 'fix')->fires);
        }
        self::assertNull($this->activePause($card));
    }

    public function test_a_failing_loop_with_no_healthy_state_still_hits_the_fix_limit(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 1, refill: true)]);
        $pullRequest = $this->pullRequest($card);
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $pullRequest->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');

        self::assertSame(PauseKind::WorkLimit, $this->activePause($card)?->kind);
    }

    public function test_a_healthy_pull_request_does_not_refill_the_budget_of_another_one_that_is_broken(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 3, refill: true)]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $upper->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $base->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');

        self::assertSame(2, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_work_limit_pause_lifts_when_the_pull_request_turns_healthy(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 1, refill: true)]);
        $pullRequest = $this->pullRequest($card);
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $pullRequest->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');
        $pause = $this->activePause($card);
        self::assertSame(PauseKind::WorkLimit, $pause?->kind);

        $pullRequest->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('refilled', $pause->releaseReason);
        self::assertNull($this->activePause($card));
        self::assertSame(0, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_work_limit_pause_with_no_refill_stays_when_the_pull_request_turns_healthy(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 1)]);
        $pullRequest = $this->pullRequest($card);
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $pullRequest->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');
        $pullRequest->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame(PauseKind::WorkLimit, $this->activePause($card)?->kind);
    }

    public function test_a_retries_pause_that_a_person_releases_fires_the_rule_again_with_a_fresh_budget(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)], backoffMinutes: []);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->releaseByPerson($card);
        $this->evaluate($card, '2026-10-02 13:00:00');

        self::assertSame(['stuck'], $this->firedRules());
        $next = $this->activePause($card);
        self::assertNotNull($next, 'The rule fired again and its first refusal paused the card again.');
        self::assertNotSame((string) $pause->id, (string) $next->id);
        self::assertSame(1, $this->ruleState($card, 'stuck')->attempts);
    }

    public function test_a_work_limit_pause_that_a_person_releases_lets_the_rule_request_work_again(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['card.type' => ['types' => ['bug']]], limit: 1)]);
        $this->setType($card, 'bug');
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $this->setType($card, 'feature');
        $this->evaluate($card);
        $this->setType($card, 'bug');
        $this->evaluate($card);
        self::assertSame(PauseKind::WorkLimit, $this->activePause($card)?->kind);

        $this->releaseByPerson($card);
        $this->evaluate($card, '2026-10-02 13:00:00');

        self::assertNull($this->activePause($card));
        self::assertCount(1, $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_paused_card_runs_only_its_release_rules(): void
    {
        $card = $this->boundCard(self::holdRules());
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->setType($card, 'security');
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($pause->releasedAt);
        self::assertSame([], $this->liveRequests($card));
        self::assertNull($this->ruleStateOrNull($card, 'work'));
        self::assertNotNull($this->ruleState($card, 'unhold')->fingerprint);
    }

    public function test_a_release_rule_that_lifts_the_pause_lets_the_other_rules_fire_in_the_same_evaluation(): void
    {
        $card = $this->boundCard(self::holdRules());
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame(ReleasePause::RELEASE_REASON, $pause->releaseReason);
        self::assertNull($this->activePause($card), 'The pause rule stays true, so it does not fire again.');
        self::assertCount(1, $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'work')->fires);
    }

    public function test_a_release_rule_that_was_true_before_the_pause_still_releases_it(): void
    {
        $card = $this->boundCard([
            ['id' => 'unhold', 'when' => ['card.type' => ['types' => ['bug']]], 'then' => ['release' => ['reason' => 'on-hold']]],
            [
                'id' => 'hold',
                'slot' => 'one',
                'when' => ['card.type' => ['types' => ['bug']]],
                'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['card.type' => ['types' => ['epic']]]]],
            ],
        ]);
        $this->setType($card, 'bug');
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertTrue($this->ruleState($card, 'unhold')->truth);

        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame(ReleasePause::RELEASE_REASON, $pause->releaseReason);
    }

    public function test_a_second_release_rule_does_not_fire_after_the_first_lifts_the_pause(): void
    {
        $unhold = static fn (string $id): array => ['id' => $id, 'when' => ['card.type' => ['types' => ['bug']]], 'then' => ['release' => ['reason' => 'on-hold']]];
        $card = $this->boundCard([
            $unhold('unhold-a'),
            $unhold('unhold-b'),
            [
                'id' => 'hold',
                'slot' => 'one',
                'when' => ['card.type' => ['types' => ['bug']]],
                'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['card.type' => ['types' => ['epic']]]]],
            ],
        ]);
        $this->setType($card, 'bug');
        $this->evaluate($card);
        self::assertNotNull($this->activePause($card));

        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($this->activePause($card));
        self::assertSame(['unhold-a'], $this->firedRules());
    }

    public function test_a_pause_ends_the_evaluation_before_the_release_check_and_any_move(): void
    {
        $card = $this->boundCard([
            [
                'id' => 'hold',
                'slot' => 'one',
                'when' => ['card.type' => ['types' => ['bug']]],
                'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['card.type' => ['types' => ['epic']]]]],
            ],
            self::moveRule('advance', 'two', ['card.type' => ['types' => ['feature']]]),
        ]);
        $this->setType($card, 'bug');
        $this->evaluate($card);
        $this->setType($card, 'feature');

        $this->evaluate($card, '2026-10-02 14:00:00');

        self::assertSame('next', $card->column->slug);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame(PauseKind::Rule, $pause->kind);
        self::assertCount(1, $this->paused);
    }

    public function test_a_request_that_is_already_live_does_not_count_toward_the_limit(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['card.type' => ['types' => ['bug']]], limit: 2)]);
        foreach (['bug', 'feature', 'bug', 'feature', 'bug'] as $type) {
            $this->setType($card, $type);
            $this->evaluate($card);
        }

        self::assertCount(1, $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);
        self::assertNull($this->activePause($card));
    }

    public function test_a_fix_push_with_no_new_review_opens_no_second_fix_round(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['pr.changes_requested' => []], limit: 3, role: 'fix')]);
        $pullRequest = $this->pullRequest($card, headSha: 'a');
        $this->requestChanges($pullRequest);
        $this->evaluate($card);
        $first = $this->liveRequests($card);
        self::assertCount(1, $first);
        $this->finish($first[0]);

        $pullRequest->headSha = 'b';
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame(WorkRequestState::Done, $first[0]->state);
        self::assertSame([], $this->liveRequests($card));
        $state = $this->ruleState($card, 'fix');
        self::assertFalse($state->truth, 'The fix run that opened after the review answered it, so the edge resets.');
        self::assertSame(1, $state->fires);
    }

    public function test_a_review_that_arrives_during_a_conflict_fix_gets_its_own_fix_round_when_the_conflict_fix_pushes(): void
    {
        $card = $this->boundCard([self::requestRule('fix', self::FIX_CAUSES, limit: 3, role: 'fix')]);
        $pullRequest = $this->pullRequest($card, headSha: 'a');
        $pullRequest->mergeability = PullRequestMergeability::Conflicting;
        $this->em()->flush();
        $this->evaluate($card);
        $conflictFix = $this->liveRequests($card)[0];

        $this->requestChanges($pullRequest, '2026-10-02 12:05:00');
        $this->evaluate($card, '2026-10-02 12:06:00');
        self::assertCount(1, $this->liveRequests($card), 'The review adds no request while the conflict fix is live.');

        $pullRequest->headSha = 'b';
        $pullRequest->mergeability = PullRequestMergeability::Mergeable;
        $this->em()->flush();
        $this->finish($conflictFix, '2026-10-02 12:10:00');
        $this->evaluate($card, '2026-10-02 12:11:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertNotSame($conflictFix->id?->toRfc4122(), $live[0]->id?->toRfc4122());
        self::assertSame(['conflict', 'changes-requested'], array_column($this->fixEvents($card), 'reason'));
    }

    public function test_a_second_changes_requested_review_on_a_new_head_opens_a_second_fix_round(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['pr.changes_requested' => []], limit: 3, role: 'fix')]);
        $pullRequest = $this->pullRequest($card, headSha: 'a');
        $this->requestChanges($pullRequest);
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $pullRequest->headSha = 'b';
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        $this->requestChanges($pullRequest, '2026-10-02 12:55:00');
        $this->evaluate($card, '2026-10-02 13:00:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('fix', $live[0]->ruleId);
        self::assertSame(2, $this->ruleState($card, 'fix')->fires);
        self::assertNull($this->activePause($card));
    }

    public function test_a_fix_rule_on_a_stack_fixes_the_base_first_and_then_the_pull_request_stacked_on_it(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 3, role: 'fix')]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();

        $this->evaluate($card);
        $state = $this->ruleState($card, 'fix');
        self::assertTrue($base->id?->equals($state->subjectPullRequestId));
        self::assertSame(1, $state->fires);
        $first = $this->liveRequests($card)[0];
        self::assertSame($base->number, $first->context->pullRequestNumber);
        $this->finish($first);

        $base->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        $state = $this->ruleState($card, 'fix');
        self::assertTrue($upper->id?->equals($state->subjectPullRequestId));
        $live = $this->liveRequests($card);
        self::assertCount(1, $live, 'The rule stayed true, and the new subject fired it again.');
        self::assertSame($upper->number, $live[0]->context->pullRequestNumber);
        self::assertSame(1, $state->fires, 'A new subject starts a fresh fix budget.');
        self::assertEqualsCanonicalizing([
            ['reason' => 'checks-failed', 'pullRequest' => $base->number],
            ['reason' => 'checks-failed', 'pullRequest' => $upper->number],
        ], $this->fixEvents($card));
    }

    public function test_a_new_subject_waits_for_the_live_request_of_the_old_one_to_settle(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 3)]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];

        $base->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        $state = $this->ruleState($card, 'fix');
        self::assertTrue($base->id?->equals($state->subjectPullRequestId), 'The live request still serves the base.');
        self::assertSame(1, $state->fires);

        $this->finish($first);
        $this->evaluate($card, '2026-10-02 12:40:00');

        $state = $this->ruleState($card, 'fix');
        self::assertTrue($upper->id?->equals($state->subjectPullRequestId));
        self::assertCount(1, $this->liveRequests($card));
        self::assertNotSame($first, $this->liveRequests($card)[0]);
        self::assertSame(1, $state->fires);
    }

    public function test_a_work_limit_pause_releases_when_the_rule_moves_to_another_pull_request(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 1)]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);

        $base->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');
        $pause = $this->activePause($card);
        self::assertSame(PauseKind::WorkLimit, $pause?->kind);

        $base->checks = PullRequestChecks::Passed;
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('subject-changed', $pause->releaseReason);
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame($upper->number, $live[0]->context->pullRequestNumber);
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_work_limit_pause_released_for_a_new_subject_waits_for_the_live_request_of_the_old_one(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 1)]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];

        $base->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');
        $pause = $this->activePause($card);
        self::assertSame(PauseKind::WorkLimit, $pause?->kind);

        $base->checks = PullRequestChecks::Passed;
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');
        self::assertSame('subject-changed', $pause->releaseReason);
        self::assertSame([$first], $this->liveRequests($card));
        self::assertTrue($base->id?->equals($this->ruleState($card, 'fix')->subjectPullRequestId));
        self::assertSame(1, $this->ruleState($card, 'fix')->fires, 'The base keeps its count while its request is live.');

        $this->finish($first);
        $this->evaluate($card, '2026-10-02 12:40:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame($upper->number, $live[0]->context->pullRequestNumber);
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);
        self::assertNull($this->activePause($card));
    }

    public function test_a_work_limit_pause_released_from_a_hold_ends_when_the_rule_moves_to_another_pull_request(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 1)]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);

        $base->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');
        $pause = $this->activePause($card);
        self::assertSame(PauseKind::WorkLimit, $pause?->kind);

        $this->hold($card);
        $base->checks = PullRequestChecks::Passed;
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->releaseHold($card, '2026-10-02 12:25:00');
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('subject-changed', $pause->releaseReason);
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame($upper->number, $live[0]->context->pullRequestNumber);
        $state = $this->ruleState($card, 'fix');
        self::assertTrue($upper->id?->equals($state->subjectPullRequestId));
        self::assertSame(1, $state->fires);
    }

    public function test_a_work_limit_pause_released_from_a_hold_stays_on_the_same_pull_request(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['card.type' => ['types' => ['bug']]], limit: 1)]);
        $this->setType($card, 'bug');
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $this->setType($card, 'feature');
        $this->evaluate($card, '2026-10-02 12:30:00');
        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 12:31:00');
        $pause = $this->activePause($card);
        self::assertSame(PauseKind::WorkLimit, $pause?->kind);
        $this->hold($card);

        $this->releaseHold($card, '2026-10-02 12:40:00');
        $this->evaluate($card, '2026-10-02 12:41:00');

        self::assertNull($pause->releasedAt);
        self::assertSame([], $this->liveRequests($card));
        self::assertSame(0, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_rule_that_turns_false_before_it_moves_to_another_pull_request_starts_a_fresh_budget(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 1)]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);

        $base->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');

        self::assertNull($this->activePause($card));
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame($upper->number, $live[0]->context->pullRequestNumber);
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_rule_pause_reads_its_until_on_the_pull_request_it_paused(): void
    {
        $card = $this->boundCard([[
            'id' => 'hold',
            'slot' => 'one',
            'when' => ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]],
            'then' => ['pause' => ['reason' => 'red', 'until' => ['pr.checks_passed' => []]]],
        ]]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertSame(PauseKind::Rule, $pause?->kind);

        $base->checks = PullRequestChecks::Passed;
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('until-met', $pause->releaseReason);
    }

    public function test_a_true_rule_with_no_stored_subject_adopts_its_subject_and_fires_nothing(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['pr.checks_failed' => []], limit: 3)]);
        $pullRequest = $this->pullRequest($card);
        $pullRequest->checks = PullRequestChecks::Failed;
        $state = new WorkflowRuleState($card->id ?? throw new \LogicException('The card is persisted.'), $card->project, 'fix', new \DateTimeImmutable(self::NOON));
        $state->truth = true;
        $state->fires = 2;
        $this->em()->persist($state);
        $this->em()->flush();

        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame([], $this->liveRequests($card));
        $state = $this->ruleState($card, 'fix');
        self::assertSame(2, $state->fires);
        self::assertTrue($pullRequest->id?->equals($state->subjectPullRequestId));
    }

    public function test_an_evaluation_that_changes_nothing_writes_no_rule_state(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['pr.checks_failed' => []], limit: 3)]);
        $pullRequest = $this->pullRequest($card);
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->evaluate($card, '2026-10-02 12:30:00');
        $state = $this->ruleState($card, 'fix');
        $state->subjectPullRequestId = Uuid::fromString((string) $state->subjectPullRequestId);
        $this->em()->flush();
        $listener = new class {
            /** @var list<string> */
            public array $updated = [];

            public function preUpdate(PreUpdateEventArgs $args): void
            {
                $this->updated[] = $args->getObject()::class.': '.implode(',', array_keys($args->getEntityChangeSet()));
            }
        };
        $this->em()->getEventManager()->addEventListener(Events::preUpdate, $listener);

        $this->evaluate($card, '2026-10-02 12:31:00');

        self::assertSame([], $listener->updated);
    }

    public function test_a_merge_rule_binds_the_base_of_a_stack_although_the_stacked_pull_request_opened_later(): void
    {
        $card = $this->boundCard([self::requestRule('merge', ['all' => [['pr.open' => []], ['pr.base_is_merge_target' => []]]])]);
        [$base] = $this->stack($card);

        $this->evaluate($card);

        self::assertCount(1, $this->liveRequests($card));
        self::assertTrue($base->id?->equals($this->ruleState($card, 'merge')->subjectPullRequestId));
    }

    /** @return array{ForgePullRequest, ForgePullRequest} a base pull request and one stacked on it, opened later */
    private function stack(Card $card): array
    {
        $base = $this->pullRequest($card, head: 'base-branch');
        $base->openedAt = new \DateTimeImmutable('2026-10-01 09:00:00');
        $upper = $this->pullRequest($card, base: 'base-branch', head: 'upper-branch');
        $upper->openedAt = new \DateTimeImmutable('2026-10-01 10:00:00');
        $this->em()->flush();

        return [$base, $upper];
    }

    /** @return list<array<string, mixed>> */
    private static function holdRules(): array
    {
        return [
            [
                'id' => 'hold',
                'slot' => 'one',
                'when' => self::ALWAYS,
                'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['card.type' => ['types' => ['epic']]]]],
            ],
            self::requestRule('work', self::ALWAYS),
            ['id' => 'unhold', 'when' => ['card.type' => ['types' => ['bug']]], 'then' => ['release' => ['reason' => 'on-hold']]],
        ];
    }

    public function test_a_successful_move_stops_the_loop(): void
    {
        $card = $this->boundCard([
            self::moveRule('advance', 'two', self::ALWAYS),
            ['id' => 'work', 'when' => self::ALWAYS, 'then' => ['request' => ['kind' => 'implement']]],
        ]);

        $this->evaluate($card);

        self::assertSame('in-progress', $card->column->slug);
        self::assertSame([], $this->liveRequests($card));
        self::assertNull($this->ruleStateOrNull($card, 'work'));
    }

    public function test_an_open_request_waits_for_a_bridge_with_no_time_limit(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $request = $this->liveRequests($card)[0];

        $this->evaluate($card, '2026-10-02 14:00:00');
        $this->evaluate($card, '2026-10-09 14:00:00');

        self::assertSame(WorkRequestState::Open, $request->state);
        self::assertNull($this->activePause($card));
        self::assertSame([], $this->paused);
        self::assertSame([$request], $this->liveRequests($card));
    }

    public function test_a_live_request_whose_rule_left_the_template_is_cancelled(): void
    {
        $card = $this->boundCard([]);
        $request = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, 'implement', null, 'gone', new \DateTimeImmutable(self::NOON));
        $this->em()->persist($request);
        $this->em()->flush();

        $this->evaluate($card);

        self::assertSame(WorkRequestState::Cancelled, $request->state);
    }

    public function test_a_request_that_a_person_asked_for_is_left_alone_even_past_the_timeout(): void
    {
        $card = $this->boundCard([]);
        $request = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, 'implement', null, PersonWork::RULE_PREFIX.'send-agent', new \DateTimeImmutable(self::NOON));
        $this->em()->persist($request);
        $this->em()->flush();

        $this->evaluate($card);
        $this->evaluate($card, '2026-10-02 14:00:00');

        self::assertSame(WorkRequestState::Open, $request->state);
        self::assertNull($this->activePause($card));
        self::assertSame([], $this->paused);
    }

    public function test_a_live_request_of_person_work_is_left_alone_past_the_work_timeout(): void
    {
        $card = $this->boundCard([]);
        $person = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, 'session', 'session', PersonWork::RULE_PREFIX.'start-session', new \DateTimeImmutable('2026-10-01 00:00:00'));
        $stale = new WorkRequest($card->project, WorkSubject::CARD, $card->id, $card->number, 'implement', null, 'gone', new \DateTimeImmutable(self::NOON));
        $this->em()->persist($person);
        $this->em()->persist($stale);
        $this->em()->flush();

        $this->evaluate($card);

        self::assertSame(WorkRequestState::Cancelled, $stale->state);
        self::assertSame(WorkRequestState::Open, $person->state);
        self::assertNull($this->activePause($card));
    }

    public function test_a_pause_whose_rule_left_the_template_is_released(): void
    {
        $card = $this->boundCard([]);
        $pause = new CardPause($card, $card->project, 'on-hold', 'gone', PauseKind::Rule, new \DateTimeImmutable(self::NOON));
        $this->em()->persist($pause);
        $this->em()->flush();

        $this->evaluate($card);

        self::assertSame('rule-removed', $pause->releaseReason);
    }

    public function test_a_refusal_time_clears_when_the_rule_turns_false(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)]);
        $this->evaluate($card);
        self::assertNotNull($this->ruleState($card, 'stuck')->lastRefusalAt);

        $this->setType($card, 'epic');
        $this->evaluate($card, '2026-10-02 12:01:00');

        $state = $this->ruleState($card, 'stuck');
        self::assertSame([null, null], [$state->lastRefusal, $state->lastRefusalAt]);
    }

    public function test_a_held_card_fires_nothing_and_settles_no_request_until_the_hold_goes(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $stale = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, 'implement', null, 'gone', new \DateTimeImmutable(self::NOON));
        $this->hold($card);
        $this->em()->persist($stale);
        $this->em()->flush();

        $this->evaluate($card);

        self::assertSame(WorkRequestState::Open, $stale->state);
        self::assertSame([], $this->service(WorkflowRuleStateRepository::class)->findForCard($card->snapshot()->id));
        self::assertSame([], $this->firedRecords());

        $this->dropHold($card);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame(WorkRequestState::Cancelled, $stale->state);
        self::assertCount(1, $this->liveRequests($card));
    }

    public function test_a_release_fires_every_rule_that_matches_the_card_now(): void
    {
        $card = $this->boundCard([
            self::requestRule('work', self::ALWAYS),
            self::requestRule('fix', ['card.type' => ['types' => ['bug']]]),
            self::moveRule('stuck', 'three', self::NOT_EPIC),
        ]);
        $this->hold($card);
        $work = new WorkflowRuleState($card->id ?? throw new \LogicException('The card is persisted.'), $card->project, 'work');
        $work->truth = true;
        $work->fires = 1;
        $stuck = new WorkflowRuleState($card->id ?? throw new \LogicException('The card is persisted.'), $card->project, 'stuck');
        $stuck->truth = true;
        $stuck->attempts = 2;
        $stuck->dueAt = new \DateTimeImmutable('2026-10-02 18:00:00');
        $stuck->lastRefusal = 'workflow-slot-missing';
        $stuck->lastRefusalAt = new \DateTimeImmutable(self::NOON);
        $this->em()->persist($work);
        $this->em()->persist($stuck);
        $this->em()->flush();

        $this->releaseHold($card);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame(['work', 'stuck'], $this->firedRules());
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('work', $live[0]->ruleId);
        self::assertSame(1, $this->ruleState($card, 'work')->fires);
        self::assertFalse($this->ruleState($card, 'fix')->truth);
        $state = $this->ruleState($card, 'stuck');
        self::assertSame([true, 1, '2026-10-02 12:11:00', 'workflow-slot-missing'], [$state->truth, $state->attempts, $state->dueAt?->format('Y-m-d H:i:s'), $state->lastRefusal]);
    }

    public function test_a_release_fires_the_rules_of_a_card_that_got_a_baseline_mark_while_held(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->hold($card);
        $this->saveAutomation($card, false);
        $this->saveAutomation($card, true);
        self::assertTrue($this->service(WorkflowPendingBaselineRepository::class)->isMarked($card->id ?? throw new \LogicException('A flushed card has an id.')));

        $this->releaseHold($card);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame(['work'], $this->firedRules());
        self::assertCount(1, $this->liveRequests($card));
    }

    public function test_a_release_opens_no_second_request_while_one_is_live(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->hold($card);
        $request = $this->openRequestWhileHeld($card);

        $this->releaseHold($card, '2026-10-02 12:01:00');
        $this->evaluate($card, '2026-10-02 12:02:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame($request, $live[0]);
        self::assertSame(WorkRequestState::Open, $request->state);
    }

    public function test_a_hold_cancels_the_open_request_and_a_release_asks_for_the_work_again(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $request = $this->liveRequests($card)[0];

        $this->hold($card);

        self::assertSame(WorkRequestState::Cancelled, $request->state);

        $this->releaseHold($card, '2026-10-02 12:01:00');
        $this->evaluate($card, '2026-10-02 12:02:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertNotSame($request, $live[0]);
    }

    public function test_a_release_opens_a_new_request_when_the_last_one_settled(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];
        $this->finish($first);
        $this->evaluate($card, '2026-10-02 12:30:00');
        self::assertSame([], $this->liveRequests($card), 'A rule that stays true fires once.');
        $this->hold($card);

        $this->releaseHold($card, '2026-10-02 12:40:00');
        $this->evaluate($card, '2026-10-02 12:41:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertNotSame($first, $live[0]);
    }

    public function test_a_release_gives_a_limited_rule_a_fresh_work_budget(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS, limit: 1)]);
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        self::assertSame(1, $this->ruleState($card, 'work')->fires);
        $this->hold($card);

        $this->releaseHold($card, '2026-10-02 12:40:00');
        $this->evaluate($card, '2026-10-02 12:41:00');

        self::assertNull($this->activePause($card));
        self::assertCount(1, $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'work')->fires);
    }

    public function test_a_release_fires_a_move_rule_and_the_move_ends_the_pass(): void
    {
        $card = $this->boundCard([
            self::moveRule('advance', 'two', self::ALWAYS),
            self::requestRule('work', self::ALWAYS),
        ]);
        $this->hold($card);
        $advance = new WorkflowRuleState($card->id ?? throw new \LogicException('The card is persisted.'), $card->project, 'advance');
        $advance->truth = true;
        $this->em()->persist($advance);
        $this->em()->flush();

        $this->releaseHold($card);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame('in-progress', $card->column->slug);
        self::assertSame(['advance'], $this->firedRules());
        self::assertSame([], $this->liveRequests($card));
        self::assertNull($this->ruleStateOrNull($card, 'work'));
    }

    public function test_a_release_keeps_a_retries_pause_whose_facts_did_not_change(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)], backoffMinutes: []);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        $fired = $this->firedRecords();
        $this->hold($card);

        $this->releaseHold($card, '2026-10-02 12:01:00');
        $this->evaluate($card, '2026-10-02 12:02:00');

        self::assertNull($pause->releasedAt);
        self::assertSame($fired, $this->firedRecords());
    }

    public function test_a_release_cancels_a_request_whose_rule_no_longer_applies(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->hold($card);
        $request = $this->openRequestWhileHeld($card);
        $this->moveTo($card, 'done');
        $this->evaluate($card, '2026-10-02 12:01:00');
        self::assertSame(WorkRequestState::Open, $request->state);
        $fired = $this->firedRecords();

        $this->releaseHold($card);
        $this->evaluate($card, '2026-10-02 12:02:00');

        self::assertSame(WorkRequestState::Cancelled, $request->state);
        self::assertSame($fired, $this->firedRecords());
    }

    public function test_a_release_resets_the_rules_of_the_slot_the_card_left_so_they_fire_on_return(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        self::assertTrue($this->ruleState($card, 'work')->truth);
        $this->hold($card);
        $this->moveTo($card, 'in-progress');

        $this->releaseHold($card);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertFalse($this->ruleState($card, 'work')->truth);
        self::assertSame(0, $this->ruleState($card, 'work')->fires);

        $this->moveTo($card, 'next');
        $this->evaluate($card, '2026-10-02 12:02:00');

        self::assertCount(1, $this->liveRequests($card));
    }

    public function test_a_retries_pause_whose_facts_changed_while_held_releases_and_the_rule_fires_again(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)], backoffMinutes: []);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        $this->hold($card);
        $this->setType($card, 'bug');

        $this->releaseHold($card);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame('facts-changed', $pause->releaseReason);
        self::assertSame(['stuck'], $this->firedRules());
        $next = $this->activePause($card);
        self::assertNotNull($next, 'The rule fired again and its first refusal paused the card again.');
        self::assertNotSame($pause, $next);
    }

    public function test_a_card_held_again_right_after_a_release_fires_its_rules_when_the_hold_goes(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $this->hold($card);
        $this->releaseHold($card);
        $this->hold($card);

        $this->evaluate($card, '2026-10-02 12:30:00');
        self::assertSame([], $this->liveRequests($card));

        $this->dropHold($card);
        $this->evaluate($card, '2026-10-02 12:31:00');

        self::assertCount(1, $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'work')->fires);
    }

    public function test_a_card_whose_board_automation_is_off_fires_nothing_and_turning_it_on_again_is_quiet(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS), self::requestRule('fix', ['card.type' => ['types' => ['bug']]])]);
        $this->saveAutomation($card, false);

        $this->evaluate($card);

        self::assertSame([], $this->liveRequests($card));
        self::assertSame([], $this->service(WorkflowRuleStateRepository::class)->findForCard($card->snapshot()->id));

        $this->saveAutomation($card, true);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame([], $this->liveRequests($card));
        self::assertSame([], $this->firedRecords());
        self::assertTrue($this->ruleState($card, 'work')->truth);

        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 12:02:00');

        self::assertSame(['fix'], $this->firedRules());
    }

    public function test_a_project_with_no_workflow_is_left_alone(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('engine-unbound'), 'next');

        $this->evaluate($card);

        self::assertSame([], $this->service(WorkflowRuleStateRepository::class)->findForCard($card->snapshot()->id));
        self::assertSame([], $this->liveRequests($card));
    }

    public function test_a_lifecycle_card_with_an_approved_design_moves_to_implementation_and_then_requests_the_implementation(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-lifecycle');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'tech-design');
        $this->document($card, 'tech-design');

        $this->evaluate($card);

        self::assertSame('in-progress', $card->column->slug);
        self::assertSame([], $this->liveRequests($card));

        $this->evaluate($card, '2026-10-02 12:01:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame(['implement', 'implement'], [$live[0]->kind, $live[0]->ruleId]);
    }

    public function test_a_revised_design_document_back_in_review_asks_for_no_new_design(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-design-revision');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'tech-design');
        $design = $this->document($card, 'tech-design', DocumentStatus::ChangesRequested);
        $this->evaluate($card);
        self::assertSame(['tech-design-revise'], $this->requestRuleIds($card));

        $this->setStatus($design, DocumentStatus::InReview);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame('tech-design', $card->column->slug);
        self::assertSame(['tech-design-revise'], $this->requestRuleIds($card));
    }

    public function test_an_undone_design_approval_asks_for_no_new_design(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-design-undo');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'tech-design');
        $this->block($card);
        $design = $this->document($card, 'tech-design');
        $this->evaluate($card);
        self::assertSame('tech-design', $card->column->slug);

        $this->setStatus($design, DocumentStatus::InReview);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame('tech-design', $card->column->slug);
        self::assertSame([], $this->requestRuleIds($card));
    }

    public function test_a_design_approved_straight_after_a_change_request_only_moves_the_card(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-design-approved-after-changes');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'tech-design');
        $design = $this->document($card, 'tech-design', DocumentStatus::ChangesRequested);
        $this->evaluate($card);
        self::assertSame(['tech-design-revise'], $this->requestRuleIds($card));

        $this->setStatus($design, DocumentStatus::Approved);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame('in-progress', $card->column->slug);
        self::assertSame(['tech-design-approved'], $this->firedRules());
        self::assertSame(['tech-design-revise'], $this->requestRuleIds($card));
    }

    public function test_a_revised_product_document_back_in_review_asks_for_no_new_session(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-product-revision');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'product-design');
        $product = $this->document($card, 'product-design', DocumentStatus::ChangesRequested);
        $this->evaluate($card);
        self::assertSame(['product-design-revise'], $this->requestRuleIds($card));

        $this->setStatus($product, DocumentStatus::InReview);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame('product-design', $card->column->slug);
        self::assertSame(['product-design-revise'], $this->requestRuleIds($card));
    }

    /** The board updates live from CardChanged, so each change the engine makes to a card dispatches one. */
    public function test_an_engine_move_and_its_work_request_reach_the_live_board(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-live');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'tech-design');
        $this->document($card, 'tech-design');
        $changed = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CardChanged::class, static function (CardChanged $event) use (&$changed): void {
            $changed[] = (string) $event->cardId;
        });

        $this->evaluate($card);
        $afterMove = \count($changed);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertGreaterThanOrEqual(1, $afterMove);
        self::assertGreaterThan($afterMove, \count($changed));
        self::assertSame([(string) $card->id], array_values(array_unique($changed)));
    }

    public function test_a_lifecycle_fix_round_that_pushes_nothing_asks_for_a_new_review_and_a_second_failure_asks_for_fix_round_2(): void
    {
        [$card, $pullRequest] = $this->failedAgentReviewWithFixRound('engine-no-push-fix');
        $this->finish($this->liveRequestOfKind($card, 'fix'), '2026-10-02 12:20:00');

        $this->evaluate($card, '2026-10-02 12:21:00');

        $review = $this->liveRequestOfKind($card, 'review');
        self::assertSame(['agent-review', self::HEAD_ONE], [$review->ruleId, $pullRequest->headSha]);
        self::assertSame(['review'], $this->liveKinds($card));
        self::assertFalse($this->ruleState($card, 'fix-agent-review')->truth, 'An answered review no longer reads as failed.');

        $this->finish($review, '2026-10-02 12:30:00');
        $this->agentReview($card, $pullRequest, AgentReviewConclusion::Failure, '2026-10-02 12:29:00');
        $this->evaluate($card, '2026-10-02 12:31:00');

        $fix = $this->liveRequestOfKind($card, 'fix');
        self::assertSame(['fix-agent-review', 'agent-review'], [$fix->ruleId, $fix->context->reason]);
        self::assertSame(2, $this->ruleState($card, 'fix-agent-review')->fires);
        self::assertNull($this->activePause($card));
    }

    public function test_a_lifecycle_fix_round_that_pushes_asks_for_a_review_of_the_new_head(): void
    {
        [$card, $pullRequest] = $this->failedAgentReviewWithFixRound('engine-push-fix');
        $pullRequest->headSha = self::HEAD_TWO;
        $this->finish($this->liveRequestOfKind($card, 'fix'), '2026-10-02 12:20:00');

        $this->evaluate($card, '2026-10-02 12:21:00');

        self::assertSame(['review'], $this->liveKinds($card));
        self::assertSame('agent-review', $this->liveRequestOfKind($card, 'review')->ruleId);
        self::assertFalse($this->ruleState($card, 'fix-agent-review')->truth, 'The new head has no review yet.');
        self::assertSame(1, $this->ruleState($card, 'fix-agent-review')->fires);
    }

    public function test_a_paused_fix_rule_ignores_the_draft_flag_and_an_approval_that_overrides_the_review_asks_for_no_fix_round(): void
    {
        [$card, $pullRequest, $pause] = $this->pausedFixRule('engine-paused-fix-approval');

        $pullRequest->draft = false;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:22:00');
        self::assertNull($pause->releasedAt, 'Marking the pull request ready does not end the pause.');
        self::assertSame([], $this->liveKinds($card));

        $pullRequest->review = PullRequestReview::Approved;
        $pullRequest->coveredSha = $pullRequest->headSha;
        $pullRequest->latestApprovedAt = new \DateTimeImmutable('2026-10-02 12:23:00');
        $pullRequest->latestApprovalId = 'PRR_latest';
        $pullRequest->latestApproverLogin = 'ubermuda';
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:24:00');

        self::assertSame(1, $this->ruleState($card, 'fix-agent-review')->fires);
        self::assertSame([], $this->liveKinds($card), 'The overridden review asks for no fix round.');
    }

    public function test_a_paused_fix_rule_stays_paused_while_the_review_check_and_its_comment_are_posted(): void
    {
        [$card, $pullRequest, $pause] = $this->pausedFixRule('engine-paused-fix-posts', [new AgentReviewFinding('src/A.php', 1, 2, AgentReviewSeverity::Important, 'Null read', 'Body.')]);
        $review = $this->service(AgentReviewRepository::class)->findOneBy(['card' => $card]);
        self::assertNotNull($review);
        $uncommented = $this->service(UncommentedAgentReviews::class);
        self::assertSame([], $uncommented->among([$review]), 'A check posted outside the comment window asks for no comment yet.');

        $review->postedAt = new \DateTimeImmutable();
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:23:00');

        self::assertSame([$review], $uncommented->among([$review]), 'The check is posted and its summary comment is due.');
        self::assertNull($pause->releasedAt, 'A post of the review check does not end the pause.');
        self::assertSame([], $this->liveKinds($card));
        self::assertSame(1, $this->ruleState($card, 'fix-agent-review')->fires);

        $this->em()->persist(new PullRequestNotice($card->project, $pullRequest->id ?? throw new \LogicException('Flushed.'), 'github', $pullRequest->repository, $pullRequest->number, AgentReviewNoticeBody::key($review->id ?? throw new \LogicException('Flushed.')), new \DateTimeImmutable()));
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:24:00');

        self::assertSame([], $uncommented->among([$review]), 'The summary comment is posted.');
        self::assertNull($pause->releasedAt, 'A post of the summary comment does not end the pause.');
        self::assertSame([], $this->liveKinds($card));
        self::assertSame(1, $this->ruleState($card, 'fix-agent-review')->fires);
    }

    public function test_a_fix_rule_that_stopped_for_a_person_stays_paused_on_a_new_head_with_a_new_failed_review(): void
    {
        [$card, $pullRequest, $pause] = $this->pausedFixRule('engine-paused-fix-head');

        $pullRequest->headSha = self::HEAD_TWO;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:25:00');
        self::assertNull($pause->releasedAt, 'A new head with no review yet does not end the pause.');
        self::assertSame([], $this->liveKinds($card), 'The paused card asks for no review of the new head.');

        $this->agentReview($card, $pullRequest, AgentReviewConclusion::Failure, '2026-10-02 12:30:00');
        $this->evaluate($card, '2026-10-02 12:31:00');

        self::assertNull($pause->releasedAt, 'A new failed review with the same findings does not end the pause.');
        self::assertSame([], $this->liveKinds($card));
        self::assertSame(1, $this->ruleState($card, 'fix-agent-review')->fires);

        $this->releaseByPerson($card);
        $this->evaluate($card, '2026-10-02 12:40:00');

        self::assertSame('released-by-person', $pause->releaseReason);
        self::assertSame(['fix'], $this->liveKinds($card), 'A person ends the pause, and the fix rule runs again.');
    }

    public function test_a_fix_rule_that_stopped_for_a_person_is_released_when_its_pull_request_merges(): void
    {
        [$card, $pullRequest, $pause] = $this->pausedFixRule('engine-paused-fix-merged');

        $pullRequest->state = PullRequestState::Merged;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('pull-request-closed', $pause->releaseReason);
        self::assertNull($this->activePause($card));
        self::assertNotContains('fix', $this->liveKinds($card));
    }

    public function test_a_fix_rule_that_stopped_for_a_person_is_released_when_the_card_leaves_its_slot(): void
    {
        [$card, , $pause] = $this->pausedFixRule('engine-paused-fix-moved');

        $this->moveTo($card, 'backlog');
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('left-slot', $pause->releaseReason);
        self::assertNull($this->activePause($card));
    }

    public function test_a_review_request_waiting_for_its_retry_ignores_the_draft_flag(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-review-retry-draft');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-progress');
        $pullRequest = $this->pullRequest($card, headSha: self::HEAD_ONE);
        $pullRequest->draft = true;
        $this->em()->flush();
        $this->evaluate($card);
        $this->refuse($this->liveRequestOfKind($card, 'review'), 'failed', '2026-10-02 12:02:00');
        $this->evaluate($card, '2026-10-02 12:03:00');
        $state = $this->ruleState($card, 'agent-review');
        $attempts = $state->attempts;
        self::assertGreaterThan(0, $attempts);
        self::assertGreaterThan(new \DateTimeImmutable('2026-10-02 12:03:00'), $state->dueAt);

        $pullRequest->draft = false;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:03:30');

        self::assertSame($attempts, $this->ruleState($card, 'agent-review')->attempts);
        self::assertSame([], $this->liveKinds($card), 'The draft flag does not start the retry before its due time.');
    }

    /**
     * A lifecycle card whose fix round stopped for a person at 12:20, so the rule fix-agent-review is paused.
     *
     * @param list<AgentReviewFinding> $findings
     *
     * @return array{Card, ForgePullRequest, CardPause}
     */
    private function pausedFixRule(string $slug, array $findings = []): array
    {
        [$card, $pullRequest] = $this->failedAgentReviewWithFixRound($slug, $findings);
        $this->refuse($this->liveRequestOfKind($card, 'fix'), 'needs-person', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([PauseKind::WorkStopped, 'fix-agent-review'], [$pause->kind, $pause->ruleId]);

        return [$card, $pullRequest, $pause];
    }

    /**
     * A lifecycle card in Implementation whose review of HEAD_ONE failed at 12:04, with fix round 1 live.
     *
     * @param list<AgentReviewFinding> $findings
     *
     * @return array{Card, ForgePullRequest}
     */
    private function failedAgentReviewWithFixRound(string $slug, array $findings = []): array
    {
        self::bootKernel();
        $project = $this->workflowProject($slug);
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-progress');
        $pullRequest = $this->pullRequest($card, headSha: self::HEAD_ONE);
        $pullRequest->draft = true;
        $this->em()->flush();
        $this->evaluate($card);

        $this->finish($this->liveRequestOfKind($card, 'review'), '2026-10-02 12:05:00');
        $this->agentReview($card, $pullRequest, AgentReviewConclusion::Failure, '2026-10-02 12:04:00', $findings);
        $this->evaluate($card, '2026-10-02 12:06:00');

        $fix = $this->liveRequestOfKind($card, 'fix');
        self::assertSame(['fix-agent-review', 'agent-review'], [$fix->ruleId, $fix->context->reason]);
        self::assertSame(1, $this->ruleState($card, 'fix-agent-review')->fires);

        return [$card, $pullRequest];
    }

    /** @param list<AgentReviewFinding> $findings */
    private function agentReview(Card $card, ForgePullRequest $pullRequest, AgentReviewConclusion $conclusion, string $at, array $findings = []): void
    {
        $review = new AgentReview($card->project, $card, $pullRequest, $pullRequest->headSha ?? '', 'Review.', $conclusion, $findings, createdAt: new \DateTimeImmutable($at));
        $review->postedAt = new \DateTimeImmutable($at);
        $this->em()->persist($review);
        $this->em()->flush();
    }

    public function test_a_lifecycle_card_that_a_person_moves_to_the_backlog_stays_there_until_its_pull_request_reopens(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-reopen');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-progress');
        $pullRequest = $this->pullRequest($card);
        $this->evaluate($card);

        $this->moveTo($card, 'backlog');
        $this->evaluate($card, '2026-10-02 12:01:00');
        self::assertSame('backlog', $card->column->slug);

        $pullRequest->state = PullRequestState::Closed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:02:00');
        self::assertSame('backlog', $card->column->slug);

        $pullRequest->state = PullRequestState::Open;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:03:00');
        self::assertSame('in-progress', $card->column->slug);
    }

    #[DataProvider('shippedTemplates')]
    public function test_a_card_that_reaches_a_terminal_column_requests_one_teardown_per_arrival(string $template): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-teardown');
        if ('lifecycle' === $template) {
            $this->bindLifecycle($project);
        } else {
            $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        }
        $card = $this->card($project, 'done');

        $this->evaluate($card);
        $this->evaluate($card, '2026-10-02 12:05:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame(['teardown', 'teardown', null], [$live[0]->kind, $live[0]->ruleId, $live[0]->capability]);

        $this->evaluate($card, '2026-10-02 14:05:00');
        self::assertSame(WorkRequestState::Open, $live[0]->state);
        self::assertNull($this->activePause($card));

        $this->moveTo($card, 'next');
        $this->evaluate($card, '2026-10-02 14:06:00');
        $this->moveTo($card, 'done');
        $this->evaluate($card, '2026-10-02 14:07:00');

        $again = $this->liveRequests($card);
        self::assertCount(1, $again);
        self::assertNotSame($live[0], $again[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function shippedTemplates(): iterable
    {
        yield 'lifecycle' => ['lifecycle'];
        yield 'simple' => ['simple'];
    }

    public function test_a_lifecycle_card_in_an_open_column_whose_pull_requests_all_closed_unmerged_stays_in_its_column(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-lifecycle-closed');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-progress');
        $closed = $this->pullRequest($card, PullRequestState::Closed);
        $closed->refreshedAt = new \DateTimeImmutable('2026-10-02 11:00:00');
        $this->em()->flush();

        $this->evaluate($card);

        self::assertSame('in-progress', $card->column->slug);
    }

    public function test_a_later_rule_reads_the_work_request_an_earlier_rule_opened_in_the_same_pass(): void
    {
        $card = $this->boundCard([
            self::requestRule('work', self::ALWAYS),
            self::requestRule('follow', ['card.run.work_active' => ['kind' => 'work']]),
        ]);

        $this->evaluate($card);

        self::assertSame(['work', 'follow'], array_map(static fn (WorkRequest $request): string => $request->kind, $this->liveRequests($card)));
    }

    public function test_a_lifecycle_epic_with_an_open_child_gets_one_breakdown_per_arrival_in_implementation(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-breakdown');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->childOf($epic, 'next');

        $this->evaluate($epic);
        $first = $this->liveRequests($epic);
        self::assertSame([['breakdown', 'breakdown']], array_map(static fn (WorkRequest $request): array => [$request->kind, $request->ruleId], $first));

        $this->childOf($epic, 'next');
        $this->evaluate($epic, '2026-10-02 12:01:00');
        self::assertSame($first, $this->liveRequests($epic));

        $this->moveTo($epic, 'next');
        $this->evaluate($epic, '2026-10-02 12:02:00');
        self::assertSame(WorkRequestState::Cancelled, $first[0]->state);
        $this->moveTo($epic, 'in-progress');
        $this->evaluate($epic, '2026-10-02 12:03:00');

        $second = $this->liveRequests($epic);
        self::assertCount(1, $second);
        self::assertSame('breakdown', $second[0]->kind);
        self::assertNotSame($first[0], $second[0]);
    }

    public function test_a_lifecycle_epic_whose_children_are_finished_waits_for_its_breakdown_before_it_moves_to_done(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-breakdown-done');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->childOf($epic, 'done');

        $this->evaluate($epic);
        $breakdown = $this->liveRequests($epic);
        self::assertCount(1, $breakdown);
        self::assertSame('in-progress', $epic->column->slug);

        $breakdown[0]->state = WorkRequestState::Claimed;
        $this->em()->flush();
        $this->evaluate($epic, '2026-10-02 12:05:00');
        self::assertSame('in-progress', $epic->column->slug);

        $this->finish($breakdown[0]);
        $this->evaluate($epic, '2026-10-02 12:25:00');
        self::assertSame('done', $epic->column->slug);
    }

    public function test_a_lifecycle_epic_whose_breakdown_stopped_for_a_person_pauses_with_the_work_stopped_kind(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-breakdown-blocked');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->childOf($epic, 'done');
        $this->evaluate($epic);
        $breakdown = $this->liveRequests($epic);
        self::assertCount(1, $breakdown);

        $this->refuse($breakdown[0], 'needs-person', '2026-10-02 12:20:00');
        $this->evaluate($epic, '2026-10-02 12:25:00');

        $pause = $this->activePause($epic);
        self::assertNotNull($pause);
        self::assertSame([PauseKind::WorkStopped, 'needs-person', 'breakdown'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertSame('in-progress', $epic->column->slug);
    }

    public function test_a_lifecycle_child_waits_in_next_while_the_breakdown_of_its_epic_runs_and_moves_when_it_ends(): void
    {
        self::bootKernel();
        $this->holdEpicBranchesAsExisting();
        $project = $this->workflowProject('engine-breakdown-ended');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $breakdown = $this->workerRun($epic, 'breakdown', WorkerRunState::Running);
        $this->evaluate($epic);
        self::assertFalse($this->ruleState($epic, 'breakdown-ended')->truth);

        $child = $this->childOf($epic, 'next');
        $this->document($child, 'tech-design');
        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertSame('next', $child->column->slug);

        $breakdown->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();
        $this->finishRequests($epic);
        $records = \count($this->firedRecords());
        $this->evaluate($epic, '2026-10-02 12:10:00');
        self::assertCount($records + 1, $this->firedRecords());
        self::assertContains('breakdown-ended', $this->firedRules());

        self::assertSame(1, $this->evaluateQueued($child, '2026-10-02 12:10:00'));
        self::assertSame('in-progress', $child->column->slug);
    }

    public function test_a_lifecycle_child_in_next_starts_only_once_it_links_an_approved_tech_design(): void
    {
        self::bootKernel();
        $this->holdEpicBranchesAsExisting();
        $project = $this->workflowProject('engine-child-needs-design');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $child = $this->childOf($epic, 'next');

        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertSame('next', $child->column->slug);

        $design = $this->document($child, 'tech-design', DocumentStatus::InReview);
        $this->evaluate($child, '2026-10-02 12:10:00');
        self::assertSame('next', $child->column->slug);

        $this->setStatus($design, DocumentStatus::Approved);
        $this->evaluate($child, '2026-10-02 12:15:00');
        self::assertSame('in-progress', $child->column->slug);
    }

    public function test_a_lifecycle_child_in_next_does_not_start_on_the_design_of_another_tag(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-child-other-tag');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $child = $this->childOf($epic, 'next');
        $this->document($child, 'product-design');

        $this->evaluate($child, '2026-10-02 12:05:00');

        self::assertSame('next', $child->column->slug);
    }

    public function test_a_lifecycle_child_in_next_waits_while_work_is_requested_for_its_epic(): void
    {
        self::bootKernel();
        $this->holdEpicBranchesAsExisting();
        $project = $this->workflowProject('engine-child-epic-request');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $child = $this->childOf($epic, 'next');
        $this->document($child, 'tech-design');
        $request = $this->workRequest($epic, 'breakdown');

        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertSame('next', $child->column->slug);

        $request->state = WorkRequestState::Done;
        $this->em()->flush();
        $this->evaluate($child, '2026-10-02 12:10:00');
        self::assertSame('in-progress', $child->column->slug);
    }

    public function test_a_lifecycle_epic_child_waits_to_merge_while_a_run_of_its_epic_is_open_and_merges_when_it_ends(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-epic-child-merge-hold');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $run = $this->workerRun($epic, 'fix', WorkerRunState::Running);
        $this->evaluate($epic);
        self::assertFalse($this->ruleState($epic, 'breakdown-ended')->truth);

        $child = $this->childOf($epic, 'in-review');
        $pullRequest = $this->pullRequest($child, base: 'epic/'.$epic->number);
        $pullRequest->checks = PullRequestChecks::Passed;
        $review = new AgentReview($project, $child, $pullRequest, $pullRequest->headSha ?? '', 'Fine.', AgentReviewConclusion::Success, []);
        $review->postedAt = new \DateTimeImmutable('2026-10-02 12:00:00');
        $this->em()->persist($review);
        $this->em()->flush();
        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertFalse($this->ruleState($child, 'merge-ready-epic-child')->truth);
        self::assertSame([], array_filter($this->liveRequests($child), static fn (WorkRequest $request): bool => 'merge' === $request->kind));

        $run->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();
        $this->finishRequests($epic);
        $this->evaluate($epic, '2026-10-02 12:10:00');
        self::assertContains('breakdown-ended', $this->firedRules());

        self::assertSame(1, $this->evaluateQueued($child, '2026-10-02 12:10:00'));
        self::assertTrue($this->ruleState($child, 'merge-ready-epic-child')->truth);
        $this->liveRequestOfKind($child, 'merge');
    }

    public function test_a_lifecycle_child_with_an_open_blocker_stays_in_next_when_the_breakdown_of_its_epic_ends(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-breakdown-ended-blocked');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $breakdown = $this->workerRun($epic, 'breakdown', WorkerRunState::Running);
        $this->evaluate($epic);
        $child = $this->childOf($epic, 'next');
        $this->document($child, 'tech-design');
        $this->block($child);

        $breakdown->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();
        $this->evaluate($epic, '2026-10-02 12:10:00');

        self::assertSame(1, $this->evaluateQueued($child, '2026-10-02 12:10:00'));
        self::assertSame('next', $child->column->slug);
    }

    public function test_a_move_rule_that_only_an_open_blocker_keeps_false_stamps_the_hold_and_the_tile_refreshes(): void
    {
        $card = $this->heldCard();
        $this->document($card, 'design');
        $this->block($card);

        $this->evaluate($card);

        $held = $this->ruleState($card, 'approved')->heldByBlockerSince;
        self::assertEquals(new \DateTimeImmutable(self::NOON), $held);
        self::assertCount(1, $this->changed);
        self::assertTrue($card->id?->equals($this->changed[0]->cardId));

        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertEquals($held, $this->ruleState($card, 'approved')->heldByBlockerSince);
        self::assertCount(1, $this->changed);
    }

    public function test_the_hold_ends_when_the_blocker_closes(): void
    {
        $card = $this->heldCard();
        $this->document($card, 'design');
        $this->block($card);
        $this->evaluate($card);
        $blocker = $this->service(CardRepository::class)->findOpenBlockersOf($card)[0];
        $this->moveTo($blocker, 'done');

        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($this->ruleState($card, 'approved')->heldByBlockerSince);
        self::assertSame('in-progress', $card->column->slug);
        self::assertCount(2, $this->changed);
    }

    public function test_a_card_without_the_approval_is_not_held_by_its_blocker(): void
    {
        $card = $this->heldCard();
        $this->block($card);

        $this->evaluate($card);

        self::assertNull($this->ruleState($card, 'approved')->heldByBlockerSince);
        self::assertSame([], $this->changed);
    }

    public function test_the_hold_ends_when_the_card_leaves_the_slot(): void
    {
        $card = $this->heldCard();
        $this->document($card, 'design');
        $this->block($card);
        $this->evaluate($card);

        $this->moveTo($card, 'in-progress');
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($this->ruleState($card, 'approved')->heldByBlockerSince);
    }

    public function test_the_hold_ends_when_the_template_no_longer_has_the_rule(): void
    {
        $card = $this->heldCard();
        $this->document($card, 'design');
        $this->block($card);
        $this->evaluate($card);
        $binding = $this->service(WorkflowBindingRepository::class)->findOneByProjectId($card->project->id ?? throw new \LogicException('A stored project has an id.'));
        self::assertNotNull($binding);
        $binding->definition = [...$binding->definition, 'rules' => [self::requestRule('work', self::ALWAYS)]];
        $this->em()->flush();

        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($this->ruleState($card, 'approved')->heldByBlockerSince);
        self::assertCount(2, $this->changed);
    }

    public function test_a_request_rule_is_never_a_blocker_hold(): void
    {
        $card = $this->boundCard([['id' => 'work', 'slot' => 'one', 'when' => ['not' => ['card.blocker.open' => []]], 'then' => ['request' => ['kind' => 'work']]]]);
        $this->block($card);

        $this->evaluate($card);

        self::assertNull($this->ruleState($card, 'work')->heldByBlockerSince);
    }

    private function heldCard(): Card
    {
        return $this->boundCard([self::moveRule('approved', 'two', ['all' => [
            ['card.document.approved' => ['tag' => 'design']],
            ['not' => ['card.blocker.open' => []]],
        ]])]);
    }

    public function test_a_resumed_breakdown_of_the_epic_still_holds_the_child_in_next(): void
    {
        self::bootKernel();
        $this->holdEpicBranchesAsExisting();
        $project = $this->workflowProject('engine-breakdown-resumed');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->workerRun($epic, 'breakdown', WorkerRunState::Blocked);
        $resumed = $this->workerRun($epic, 'breakdown', WorkerRunState::Resumed);
        $this->evaluate($epic);
        $child = $this->childOf($epic, 'next');
        $this->document($child, 'tech-design');

        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertSame('next', $child->column->slug);

        $resumed->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();
        $this->finishRequests($epic);
        $this->evaluate($epic, '2026-10-02 12:10:00');

        self::assertSame(1, $this->evaluateQueued($child, '2026-10-02 12:10:00'));
        self::assertSame('in-progress', $child->column->slug);
    }

    public function test_a_lifecycle_child_waits_in_next_while_any_worker_run_of_its_epic_is_open(): void
    {
        self::bootKernel();
        $this->holdEpicBranchesAsExisting();
        $project = $this->workflowProject('engine-parent-run-ended');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $run = $this->workerRun($epic, 'implement', WorkerRunState::Running);
        $this->evaluate($epic);
        $child = $this->childOf($epic, 'next');
        $this->document($child, 'tech-design');

        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertSame('next', $child->column->slug);

        $run->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();
        $this->finishRequests($epic);
        $this->evaluate($epic, '2026-10-02 12:10:00');

        self::assertSame(1, $this->evaluateQueued($child, '2026-10-02 12:10:00'));
        self::assertSame('in-progress', $child->column->slug);
    }

    public function test_a_lifecycle_epic_in_a_design_column_wakes_its_children_when_its_run_ends_and_they_wait_for_implementation(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-design-run-ended');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->moveTo($epic, 'tech-design');
        $run = $this->workerRun($epic, 'tech-design', WorkerRunState::Running);
        $this->evaluate($epic);
        self::assertFalse($this->ruleState($epic, 'breakdown-ended')->truth);
        $child = $this->childOf($epic, 'next');
        $this->document($child, 'tech-design');
        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertSame('next', $child->column->slug);

        $run->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();
        $this->finishRequests($epic);
        $this->evaluate($epic, '2026-10-02 12:10:00');

        self::assertContains('breakdown-ended', $this->firedRules());
        self::assertSame(1, $this->evaluateQueued($child, '2026-10-02 12:10:00'));
        self::assertSame('next', $child->column->slug);
    }

    public function test_a_lifecycle_child_created_in_the_backlog_moves_to_next_and_waits_there_while_its_epic_sits_in_next(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-child-to-next');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->moveTo($epic, 'next');
        $child = $this->childOf($epic, 'backlog');
        $this->document($child, 'tech-design');

        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertSame('next', $child->column->slug);
        self::assertContains('child-to-next', $this->firedRules());

        $this->evaluate($child, '2026-10-02 12:10:00');
        self::assertSame('next', $child->column->slug);
    }

    public function test_a_follow_up_in_the_backlog_moves_to_next_when_the_card_it_follows_finishes_and_a_person_can_park_it(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-follow-up-to-next');
        $this->bindLifecycle($project);
        $followed = $this->card($project, 'in-progress');
        $followUp = $this->card($project, 'backlog');
        $this->em()->persist(new CardLink($followed, $followUp, CardLinkKind::HasFollowUp));
        $this->em()->flush();

        $this->evaluate($followUp, '2026-10-02 12:05:00');
        self::assertSame('backlog', $followUp->column->slug);

        $this->moveTo($followed, 'done');
        $this->evaluate($followUp, '2026-10-02 12:10:00');
        self::assertSame('next', $followUp->column->slug);
        self::assertContains('follow-up-to-next', $this->firedRules());

        $this->moveTo($followUp, 'backlog');
        $this->evaluate($followUp, '2026-10-02 12:15:00');
        $this->evaluate($followUp, '2026-10-02 12:20:00');
        self::assertSame('backlog', $followUp->column->slug);
    }

    public function test_a_lifecycle_child_that_a_person_moves_from_next_to_the_backlog_stays_there(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-child-parked');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $child = $this->childOf($epic, 'backlog');

        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertSame('next', $child->column->slug);

        $this->moveTo($child, 'backlog');
        $this->evaluate($child, '2026-10-02 12:10:00');
        $this->evaluate($child, '2026-10-02 12:15:00');

        self::assertSame('backlog', $child->column->slug);
    }

    public function test_a_lifecycle_child_with_an_approved_design_does_not_start_while_its_epic_sits_in_the_backlog(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-child-epic-backlog');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->moveTo($epic, 'backlog');
        $child = $this->childOf($epic, 'next');
        $this->document($child, 'tech-design');

        $this->evaluate($child, '2026-10-02 12:05:00');

        self::assertSame('next', $child->column->slug);
        self::assertFalse($this->ruleState($child, 'child-unblocked')->truth);
    }

    public function test_a_lifecycle_epic_entering_implementation_evaluates_its_children_and_a_child_with_an_approved_design_moves(): void
    {
        self::bootKernel();
        $this->holdEpicBranchesAsExisting();
        $project = $this->workflowProject('engine-epic-entered-implementation');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->moveTo($epic, 'next');
        $child = $this->childOf($epic, 'next');
        $this->document($child, 'tech-design');
        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertSame('next', $child->column->slug);

        $this->moveTo($epic, 'in-progress');
        $this->evaluate($epic, '2026-10-02 12:10:00');
        self::assertContains('epic-entered-implementation', $this->firedRules());
        $this->finishRequests($epic);

        self::assertGreaterThan(0, $this->evaluateQueued($child, '2026-10-02 12:15:00'));
        self::assertSame('in-progress', $child->column->slug);
    }

    public function test_a_lifecycle_child_created_straight_into_next_still_starts_through_its_other_rules(): void
    {
        self::bootKernel();
        $this->holdEpicBranchesAsExisting();
        $project = $this->workflowProject('engine-child-created-in-next');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $child = $this->childOf($epic, 'next');
        $this->document($child, 'tech-design');

        $this->evaluate($child, '2026-10-02 12:05:00');

        self::assertSame('in-progress', $child->column->slug);
    }

    public function test_a_lifecycle_merged_epic_with_finished_children_waits_for_its_open_run_before_it_moves_to_done(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-merged-epic-run');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->moveTo($epic, 'in-review');
        $this->childOf($epic, 'done');
        $this->pullRequest($epic, PullRequestState::Merged);
        $run = $this->workerRun($epic, 'fix', WorkerRunState::Running);

        $this->evaluate($epic);
        self::assertSame('in-review', $epic->column->slug);

        $run->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();
        $this->evaluate($epic, '2026-10-02 12:10:00');

        self::assertSame('done', $epic->column->slug);
    }

    public function test_a_lifecycle_card_with_a_merged_pull_request_moves_to_done_while_its_run_is_open(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-merged-card-run');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-review');
        $this->pullRequest($card, PullRequestState::Merged);
        $this->workerRun($card, 'fix', WorkerRunState::Running);

        $this->evaluate($card);

        self::assertSame('done', $card->column->slug);
    }

    public function test_a_lifecycle_epic_whose_last_child_merged_into_the_epic_branch_fires_the_open_rule_and_stays_out_of_done(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-epic-open');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $child = $this->childOf($epic, 'done');
        $this->pullRequest($child, PullRequestState::Merged, base: 'epic/'.$epic->number);
        $this->evaluate($epic);
        $this->finish($this->liveRequests($epic)[0]);

        $this->evaluate($epic, '2026-10-02 12:25:00');

        self::assertSame('in-progress', $epic->column->slug);
        $state = $this->ruleState($epic, 'epic-open-pull-request');
        self::assertSame('no-installation', $state->lastRefusal);
        self::assertNotNull($state->dueAt);
    }

    public function test_a_lifecycle_child_merged_into_the_epic_branch_asks_for_no_epic_preview(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-epic-preview-child');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $child = $this->childOf($epic, 'done');
        $this->pullRequest($child, PullRequestState::Merged, base: 'epic/'.$epic->number);

        $this->evaluate($child);

        self::assertSame(['teardown'], $this->liveKinds($child));
    }

    public function test_a_lifecycle_epic_in_implementation_asks_for_no_epic_preview(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-epic-preview-implementation');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->childOf($epic, 'done');
        $this->pullRequest($epic, headSha: self::HEAD_ONE);

        $this->evaluate($epic);

        self::assertNotContains('epic-preview', $this->liveKinds($epic));
    }

    public function test_a_lifecycle_epic_in_review_refreshes_its_preview_once_for_each_head_of_its_pull_request(): void
    {
        $epic = $this->epicInReview('engine-epic-preview-heads');
        $pullRequest = $this->pullRequest($epic, headSha: self::HEAD_ONE);
        $this->agentReview($epic, $pullRequest, AgentReviewConclusion::Success, '2026-10-02 11:50:00');

        $this->evaluate($epic);
        self::assertSame(['epic-preview'], $this->liveKinds($epic));
        self::assertSame(self::HEAD_ONE, $this->liveRequestOfKind($epic, 'epic-preview')->context->headSha);

        $this->finish($this->liveRequestOfKind($epic, 'epic-preview'));
        $this->evaluate($epic, '2026-10-02 12:25:00');
        self::assertSame([], $this->liveKinds($epic), 'A refresh that ended well at the head covers it.');

        $pullRequest->headSha = self::HEAD_TWO;
        $this->agentReview($epic, $pullRequest, AgentReviewConclusion::Success, '2026-10-02 12:29:00');
        $this->em()->flush();
        $this->evaluate($epic, '2026-10-02 12:30:00');
        self::assertSame(['epic-preview'], $this->liveKinds($epic), 'A fix round push is a new head.');
        self::assertSame(self::HEAD_TWO, $this->liveRequestOfKind($epic, 'epic-preview')->context->headSha);
    }

    public function test_a_lifecycle_epic_in_review_asks_for_an_agent_review_before_it_refreshes_its_preview(): void
    {
        $epic = $this->epicInReview('engine-epic-review');
        $pullRequest = $this->pullRequest($epic, headSha: self::HEAD_ONE);

        $this->evaluate($epic);
        self::assertSame(['review'], $this->liveKinds($epic));

        $this->finish($this->liveRequestOfKind($epic, 'review'));
        $this->agentReview($epic, $pullRequest, AgentReviewConclusion::Success, '2026-10-02 12:20:00');
        $this->evaluate($epic, '2026-10-02 12:25:00');
        self::assertSame(['epic-preview'], $this->liveKinds($epic));
    }

    public function test_a_lifecycle_epic_in_review_waits_for_a_fix_request_and_a_worker_run_before_it_refreshes(): void
    {
        $epic = $this->epicInReview('engine-epic-preview-busy');
        $pullRequest = $this->pullRequest($epic, headSha: self::HEAD_ONE);
        $this->agentReview($epic, $pullRequest, AgentReviewConclusion::Success, '2026-10-02 11:50:00');
        $fix = new WorkRequest($epic->project, WorkSubject::CARD, $epic->id ?? throw new \LogicException('A flushed card has an id.'), $epic->number, 'fix', null, 'fix-in-review', new \DateTimeImmutable(self::NOON));
        $this->em()->persist($fix);
        $this->em()->flush();
        $this->evaluate($epic);
        self::assertSame(['fix'], $this->liveKinds($epic));

        $this->finish($fix);
        $run = $this->workerRun($epic, 'fix', WorkerRunState::Running);
        $this->evaluate($epic, '2026-10-02 12:25:00');
        self::assertSame([], $this->liveKinds($epic));

        $run->state = WorkerRunState::Succeeded;
        $this->em()->flush();
        $this->evaluate($epic, '2026-10-02 12:30:00');
        self::assertSame(['epic-preview'], $this->liveKinds($epic));
    }

    public function test_a_lifecycle_epic_in_review_refreshes_again_for_a_head_that_lands_during_a_refresh(): void
    {
        $epic = $this->epicInReview('engine-epic-preview-race');
        $pullRequest = $this->pullRequest($epic, headSha: self::HEAD_ONE);
        $this->agentReview($epic, $pullRequest, AgentReviewConclusion::Success, '2026-10-02 11:50:00');
        $this->evaluate($epic);
        $refresh = $this->liveRequestOfKind($epic, 'epic-preview');

        $pullRequest->headSha = self::HEAD_TWO;
        $this->agentReview($epic, $pullRequest, AgentReviewConclusion::Success, '2026-10-02 12:09:00');
        $this->em()->flush();
        $this->evaluate($epic, '2026-10-02 12:10:00');
        $this->finish($refresh);
        $this->evaluate($epic, '2026-10-02 12:25:00');

        self::assertSame(['epic-preview'], $this->liveKinds($epic));
        self::assertSame(self::HEAD_TWO, $this->liveRequestOfKind($epic, 'epic-preview')->context->headSha);
    }

    public function test_a_lifecycle_epic_pauses_after_three_failed_refreshes_in_a_row(): void
    {
        $epic = $this->epicInReview('engine-epic-preview-limit');
        $pullRequest = $this->pullRequest($epic, headSha: self::HEAD_ONE);
        $this->agentReview($epic, $pullRequest, AgentReviewConclusion::Success, '2026-10-02 11:50:00');

        foreach (['12:00:00', '12:10:00', '12:20:00'] as $at) {
            $this->evaluate($epic, '2026-10-02 '.$at);
            $this->evaluate($epic, '2026-10-02 '.$at);
            $this->refuse($this->liveRequestOfKind($epic, 'epic-preview'), 'failed', '2026-10-02 '.$at);
        }
        self::assertNull($this->activePause($epic));

        $this->evaluate($epic, '2026-10-02 12:30:00');

        $pause = $this->activePause($epic);
        self::assertNotNull($pause);
        self::assertSame([PauseKind::WorkLimit, 'epic-preview'], [$pause->kind, $pause->ruleId]);
        self::assertSame([], $this->liveKinds($epic));
    }

    public function test_a_card_evaluations_call_evaluates_the_card_and_its_provided_fact_fires_the_rule(): void
    {
        $card = $this->boundCard([self::requestRule('provided', self::PROVIDED_READY)]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $transport = self::getContainer()->get('messenger.transport.card_eval');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $this->service(CardEvaluations::class)->forCards([$card->id ?? throw new \LogicException('A flushed card has an id.')]);

        $handler = new EvaluateCardHandler(new EvaluateWorkflowCardHandler($this->service(Engine::class), new MockClock(self::NOON)), $this->service(WorkflowQueuedEvaluationRepository::class), new EventDispatcher());
        $evaluations = 0;
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EvaluateCard && $message->cardId === $card->id->toRfc4122()) {
                $handler($message);
                ++$evaluations;
            }
        }

        self::assertSame(1, $evaluations);
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('provided', $live[0]->ruleId);
    }

    public function test_a_refused_rule_fires_again_at_once_when_its_provided_facts_change(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::PROVIDED_READY)]);
        $this->provider()->facts = new ProvidedFacts(ready: true);

        $this->evaluate($card);
        self::assertSame([1, '2026-10-02 12:10:00'], [$this->ruleState($card, 'stuck')->attempts, $this->ruleState($card, 'stuck')->dueAt?->format('Y-m-d H:i:s')]);

        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertSame(1, $this->ruleState($card, 'stuck')->attempts);

        $this->provider()->facts = new ProvidedFacts(ready: true, version: 2);
        $this->evaluate($card, '2026-10-02 12:06:00');

        self::assertSame(2, $this->ruleState($card, 'stuck')->attempts);
    }

    public function test_a_failing_source_skips_the_rule_that_reads_it_logs_one_error_and_runs_the_later_rules(): void
    {
        $card = $this->boundCard([
            self::requestRule('provided', self::PROVIDED_READY),
            self::requestRule('built-in', self::NOT_EPIC),
        ]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->provider()->failure = new \RuntimeException('The source is down.');

        $this->evaluate($card);

        self::assertSame(['built-in'], $this->firedRules());
        self::assertNull($this->ruleStateOrNull($card, 'provided'));
        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame('workflow.fact_source_failed', $errors[0]['message']);
        self::assertSame($this->provider()->failure, $errors[0]['context']['exception'] ?? null);
    }

    public function test_a_facts_class_that_no_provider_gives_skips_the_rule_that_reads_it_and_logs_one_error(): void
    {
        $card = $this->boundCard([
            self::requestRule('unprovided', [UnprovidedFactsReady::KEY => []]),
            self::requestRule('negated', ['not' => [UnprovidedFactsReady::KEY => []]]),
            self::requestRule('work', self::ALWAYS),
        ]);

        $this->evaluate($card);

        self::assertSame(['work'], $this->firedRules());
        self::assertNull($this->ruleStateOrNull($card, 'unprovided'));
        self::assertNull($this->ruleStateOrNull($card, 'negated'));
        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame('workflow.fact_provider_missing', $errors[0]['message']);
        self::assertSame(\stdClass::class, $errors[0]['context']['factsClass'] ?? null);
    }

    public function test_a_rule_that_reads_a_contract_interface_fires_and_logs_no_missing_provider(): void
    {
        $card = $this->boundCard([
            self::requestRule('documents', [ListsDocumentsReady::KEY => []]),
        ]);
        $this->document($card, 'tech-design');

        $this->evaluate($card);

        self::assertSame(['documents'], $this->firedRules());
        self::assertSame([], $this->errors());
    }

    public function test_a_rule_with_an_unknown_action_never_fires_and_logs_one_warning_while_the_other_rules_fire(): void
    {
        $unknown = ['id' => 'unknown', 'slot' => 'one', 'when' => self::ALWAYS, 'then' => ['jump' => ['to' => 'two']]];
        $card = $this->boundCard([$unknown, self::requestRule('work', self::ALWAYS)]);

        $this->evaluate($card);

        self::assertSame(['work'], $this->firedRules());
        self::assertNull($this->ruleStateOrNull($card, 'unknown'));
        $warnings = array_values(array_filter($this->logger->records, static fn (array $record): bool => LogLevel::WARNING === $record['level']));
        self::assertCount(1, $warnings);
        self::assertSame('workflow.action_missing', $warnings[0]['message']);
        self::assertSame('unknown', $warnings[0]['context']['ruleId'] ?? null);
        self::assertSame('jump', $warnings[0]['context']['action'] ?? null);
    }

    public function test_a_failing_source_logs_once_when_a_withdrawal_rebuilds_the_facts(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $request = $this->liveRequests($card)[0];

        $this->moveTo($card, 'in-progress');
        $this->provider()->failure = new \RuntimeException('The source is down.');
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(WorkRequestState::Cancelled, $request->state);
        self::assertCount(1, $this->errors());
    }

    public function test_a_source_that_fails_only_when_a_withdrawal_rebuilds_the_facts_logs_one_error(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $request = $this->liveRequests($card)[0];

        $this->moveTo($card, 'in-progress');
        $this->provider()->failure = new \RuntimeException('The source is down.');
        $this->provider()->buildsBeforeFailure = $this->provider()->builds + 1;
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(WorkRequestState::Cancelled, $request->state);
        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame('workflow.fact_source_failed', $errors[0]['message']);
    }

    public function test_a_missing_provider_in_a_rule_of_another_slot_logs_nothing(): void
    {
        $card = $this->boundCard([
            ['id' => 'elsewhere', 'slot' => 'two', 'when' => [UnprovidedFactsReady::KEY => []], 'then' => ['request' => ['kind' => 'elsewhere']]],
            self::requestRule('work', self::ALWAYS),
        ]);

        $this->evaluate($card);

        self::assertSame(['work'], $this->firedRules());
        self::assertSame([], $this->errors());
    }

    public function test_a_rule_that_cannot_read_its_facts_after_a_release_fires_once_when_its_source_is_back_and_it_is_true(): void
    {
        $card = $this->boundCard([self::requestRule('provided', self::PROVIDED_READY)]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->hold($card);
        $this->releaseHold($card);
        $this->provider()->failure = new \RuntimeException('The source is down.');
        $this->evaluate($card);
        self::assertNull($this->ruleStateOrNull($card, 'provided'));

        $this->provider()->failure = null;
        $this->evaluate($card, '2026-10-02 12:05:00');
        $this->evaluate($card, '2026-10-02 12:10:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('provided', $live[0]->ruleId);
        self::assertSame(1, $this->ruleState($card, 'provided')->fires);
    }

    public function test_a_pause_rule_whose_until_names_a_removed_condition_does_not_pause_the_card(): void
    {
        $card = $this->boundCard([[
            'id' => 'hold',
            'slot' => 'one',
            'when' => self::ALWAYS,
            'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['not' => ['card.gone' => []]]]],
        ], self::requestRule('work', self::ALWAYS)]);

        $this->evaluate($card);

        self::assertNull($this->activePause($card));
        self::assertNull($this->ruleStateOrNull($card, 'hold'));
        self::assertSame(['work'], $this->firedRules());
    }

    public function test_a_pause_rule_with_an_unreadable_until_keeps_its_falling_edge(): void
    {
        $card = $this->boundCard([[
            'id' => 'hold',
            'slot' => 'one',
            'when' => self::NOT_EPIC,
            'then' => ['pause' => ['reason' => 'on-hold', 'until' => self::PROVIDED_READY]],
        ]]);
        $this->provider()->facts = new ProvidedFacts(ready: false);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertSame('until-met', $pause->releaseReason);

        $this->provider()->failure = new \RuntimeException('The source is down.');
        $this->setType($card, 'epic');
        $this->evaluate($card, '2026-10-02 12:10:00');
        self::assertFalse($this->ruleState($card, 'hold')->truth);

        $this->provider()->failure = null;
        $this->provider()->facts = new ProvidedFacts(ready: false);
        $this->setType($card, 'feature');
        $this->evaluate($card, '2026-10-02 12:15:00');
        self::assertNotNull($this->activePause($card));
    }

    public function test_a_source_that_is_off_makes_its_rule_wait_with_no_error(): void
    {
        $card = $this->boundCard([self::requestRule('provided', self::PROVIDED_READY)]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->provider()->on = false;

        $this->evaluate($card);

        self::assertSame([], $this->liveRequests($card));
        self::assertNull($this->ruleStateOrNull($card, 'provided'));
        self::assertSame([], $this->errors());

        $this->provider()->on = true;
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(['provided'], $this->firedRules());
    }

    public function test_a_request_rule_waits_when_its_refill_source_is_off(): void
    {
        $rule = self::requestRule('provided', self::NOT_EPIC, limit: 3);
        $rule['then']['request']['refill'] = self::PROVIDED_READY;
        $card = $this->boundCard([$rule]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->provider()->on = false;

        $this->evaluate($card);

        self::assertSame([], $this->liveRequests($card));
        self::assertNull($this->ruleStateOrNull($card, 'provided'));

        $this->provider()->on = true;
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(['provided'], $this->firedRules());
    }

    public function test_a_rule_pause_whose_until_is_unreadable_stays(): void
    {
        $card = $this->boundCard([[
            'id' => 'hold',
            'slot' => 'one',
            'when' => self::ALWAYS,
            'then' => ['pause' => ['reason' => 'on-hold', 'until' => self::PROVIDED_READY]],
        ]]);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->provider()->failure = new \RuntimeException('The source is down.');
        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertNull($pause->releasedAt);

        $this->provider()->failure = null;
        $this->evaluate($card, '2026-10-02 12:10:00');
        self::assertSame('until-met', $pause->releaseReason);
    }

    public function test_a_retries_pause_whose_rule_is_unreadable_stays(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::PROVIDED_READY)], backoffMinutes: []);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->provider()->facts = new ProvidedFacts(ready: true, version: 2);
        $this->provider()->failure = new \RuntimeException('The source is down.');
        $this->evaluate($card, '2026-10-02 13:00:00');
        self::assertNull($pause->releasedAt);

        $this->provider()->failure = null;
        $this->evaluate($card, '2026-10-02 13:01:00');
        self::assertSame('facts-changed', $pause->releaseReason);
    }

    public function test_after_a_release_a_rule_that_cannot_read_its_facts_waits_and_the_others_fire(): void
    {
        $card = $this->boundCard([
            self::requestRule('provided', self::PROVIDED_READY),
            self::requestRule('work', self::ALWAYS),
        ]);
        $this->hold($card);
        $this->releaseHold($card);
        $this->provider()->failure = new \RuntimeException('The source is down.');

        $this->evaluate($card);

        self::assertSame(['work'], $this->firedRules());
        self::assertNull($this->ruleStateOrNull($card, 'provided'));
        self::assertCount(1, $this->liveRequests($card));
    }

    public function test_after_a_release_a_true_pause_rule_whose_until_cannot_be_read_waits(): void
    {
        $card = $this->boundCard([[
            'id' => 'hold',
            'slot' => 'one',
            'when' => self::ALWAYS,
            'then' => ['pause' => ['reason' => 'on-hold', 'until' => self::PROVIDED_READY]],
        ]]);
        $this->hold($card);
        $this->releaseHold($card);
        $this->provider()->failure = new \RuntimeException('The source is down.');

        $this->evaluate($card);

        self::assertNull($this->ruleStateOrNull($card, 'hold'));
        self::assertNull($this->activePause($card));
    }

    public function test_an_evaluation_that_an_action_starts_inline_for_the_same_card_runs_after_the_pass_that_started_it(): void
    {
        $card = $this->boundCard([
            ['id' => 'children', 'slot' => 'one', 'when' => self::ALWAYS, 'then' => ['evaluate' => ['cards' => 'children']]],
            self::requestRule('work', self::ALWAYS),
        ]);
        $this->childOf($card, 'next');
        $cardId = $card->id ?? throw new \LogicException('A flushed card has an id.');
        $engine = null;
        $starts = 0;
        $this->evaluations = new readonly class(static function () use (&$engine, &$starts, $cardId): void {
            ++$starts;
            self::assertInstanceOf(Engine::class, $engine);
            $engine->evaluate($cardId, new \DateTimeImmutable(self::NOON));
        }) implements CardEvaluations {
            public function __construct(
                private \Closure $evaluate,
            ) {
            }

            public function forCards(array $cardIds): void
            {
                ($this->evaluate)();
            }

            public function forPersonWaiting(array $cardIds): void
            {
            }

            public function isOn(): bool
            {
                return true;
            }
        };
        $engine = $this->engine();

        $engine->evaluate($cardId, new \DateTimeImmutable(self::NOON));

        self::assertSame(1, $starts);
        self::assertTrue($this->ruleState($card, 'children')->truth);
        self::assertSame(1, $this->ruleState($card, 'work')->fires);
        self::assertCount(1, $this->liveRequests($card));
    }

    public function test_a_stored_copy_with_a_condition_this_instance_lacks_runs_every_other_rule(): void
    {
        $card = $this->boundCard([
            self::requestRule('gone', ['card.gone' => ['any' => 'value']]),
            self::requestRule('negated-gone', ['not' => ['card.gone' => []]]),
            self::requestRule('work', self::ALWAYS),
        ]);

        $this->evaluate($card);

        self::assertSame(['work'], $this->firedRules());
        self::assertNull($this->ruleStateOrNull($card, 'gone'));
        self::assertNull($this->ruleStateOrNull($card, 'negated-gone'));
        self::assertSame([], $this->errors());
    }

    public function test_an_ask_opens_once_on_the_rising_edge_and_keeps_its_item_id(): void
    {
        $card = $this->boundCard([self::askRule('ask', self::PROVIDED_READY)]);
        $this->provider()->facts = new ProvidedFacts(ready: true);

        $this->evaluate($card);
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertCount(1, $this->asks->opened);
        self::assertSame($this->asks->opened[0]['itemId'], $this->ruleState($card, 'ask')->askItemId);
        self::assertSame([], $this->asks->withdrawn);
    }

    public function test_an_ask_withdraws_on_the_falling_edge_and_opens_again_on_the_next_rising_edge(): void
    {
        $card = $this->boundCard([self::askRule('ask', self::PROVIDED_READY)]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->evaluate($card);
        $first = $this->asks->opened[0]['itemId'];

        $this->provider()->facts = new ProvidedFacts(ready: false);
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertEquals([['itemId' => $first, 'reason' => 'no-longer-holds']], $this->asks->withdrawn);
        self::assertNull($this->ruleState($card, 'ask')->askItemId);

        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->evaluate($card, '2026-10-02 12:10:00');

        self::assertCount(2, $this->asks->opened);
        self::assertSame($this->asks->opened[1]['itemId'], $this->ruleState($card, 'ask')->askItemId);
        self::assertCount(1, $this->asks->withdrawn);
    }

    public function test_an_ask_withdraws_when_the_card_leaves_the_slot_of_the_rule(): void
    {
        $card = $this->boundCard([self::askRule('ask', self::ALWAYS)]);
        $this->evaluate($card);
        $item = $this->asks->opened[0]['itemId'];

        $this->moveTo($card, 'in-progress');
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertEquals([['itemId' => $item, 'reason' => 'left-slot']], $this->asks->withdrawn);
        self::assertNull($this->ruleState($card, 'ask')->askItemId);
    }

    public function test_an_ask_withdraws_when_the_template_no_longer_has_the_rule(): void
    {
        $card = $this->boundCard([self::askRule('ask', self::ALWAYS), self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $item = $this->asks->opened[0]['itemId'];
        $binding = $this->service(WorkflowBindingRepository::class)->findOneByProjectId($card->project->id ?? throw new \LogicException('A stored project has an id.'));
        self::assertNotNull($binding);
        $binding->definition = [...$binding->definition, 'rules' => [self::requestRule('work', self::ALWAYS)]];
        $this->em()->flush();

        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertEquals([['itemId' => $item, 'reason' => 'rule-removed']], $this->asks->withdrawn);
        self::assertNull($this->ruleState($card, 'ask')->askItemId);
    }

    public function test_a_baseline_withdraws_an_ask_whose_rule_is_false_and_keeps_one_whose_rule_holds(): void
    {
        $card = $this->boundCard([self::askRule('ask', self::PROVIDED_READY)]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->evaluate($card);
        $item = $this->asks->opened[0]['itemId'];
        $cardId = $card->id ?? throw new \LogicException('A flushed card has an id.');
        $projectId = $card->project->id ?? throw new \LogicException('A stored project has an id.');

        $this->service(WorkflowPendingBaselineRepository::class)->markCards($projectId, [$cardId]);
        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertSame([], $this->asks->withdrawn);
        self::assertEquals($item, $this->ruleState($card, 'ask')->askItemId);

        $this->service(WorkflowPendingBaselineRepository::class)->markCards($projectId, [$cardId]);
        $this->provider()->facts = new ProvidedFacts(ready: false);
        $this->evaluate($card, '2026-10-02 12:10:00');
        self::assertEquals([['itemId' => $item, 'reason' => 'no-longer-holds']], $this->asks->withdrawn);
        self::assertNull($this->ruleState($card, 'ask')->askItemId);
    }

    public function test_with_the_inbox_off_an_ask_pauses_the_card_until_the_inbox_is_on_and_then_asks(): void
    {
        $card = $this->boundCard([self::askRule('ask', self::ALWAYS)]);
        $this->asks->on = false;

        $this->evaluate($card);
        $pause = $this->activePause($card);

        self::assertNotNull($pause);
        self::assertSame(PauseKind::Rule, $pause->kind);
        self::assertSame('inbox-off', $pause->reason);
        self::assertSame([], $this->asks->opened);

        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertNull($pause->releasedAt);
        self::assertSame([], $this->asks->opened);

        $this->asks->on = true;
        $this->evaluate($card, '2026-10-02 12:10:00');

        self::assertSame('inbox-on', $pause->releaseReason);
        self::assertNull($this->activePause($card));
        self::assertCount(1, $this->asks->opened);
        self::assertSame($this->asks->opened[0]['itemId'], $this->ruleState($card, 'ask')->askItemId);
    }

    public function test_an_ask_pause_ends_when_the_rule_stops_holding_even_with_the_inbox_off(): void
    {
        $card = $this->boundCard([self::askRule('ask', self::PROVIDED_READY)]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->asks->on = false;
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->provider()->facts = new ProvidedFacts(ready: false);
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame('facts-changed', $pause->releaseReason);
        self::assertSame([], $this->asks->opened);
    }

    public function test_a_true_rule_whose_action_refires_fires_again_when_its_facts_change(): void
    {
        $card = $this->boundCard([['id' => 'comment', 'when' => self::PROVIDED_READY, 'then' => ['forge-write' => ['write' => 'comment', 'comment' => 'fix-run']]]]);
        $this->provider()->facts = new ProvidedFacts(ready: true);

        $this->evaluate($card);
        $this->evaluate($card, '2026-10-02 12:01:00');
        self::assertSame(1, $this->ruleState($card, 'comment')->fires);

        $this->provider()->facts = new ProvidedFacts(ready: true, version: 2);
        $this->evaluate($card, '2026-10-02 12:02:00');

        self::assertSame(2, $this->ruleState($card, 'comment')->fires);
    }

    public function test_a_true_rule_whose_action_does_not_refire_waits_for_a_new_edge_when_its_facts_change(): void
    {
        $card = $this->boundCard([['id' => 'draft', 'when' => self::PROVIDED_READY, 'then' => ['forge-write' => ['write' => 'draft']]]]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->evaluate($card);
        self::assertSame(1, $this->ruleState($card, 'draft')->fires);

        $this->provider()->facts = new ProvidedFacts(ready: true, version: 2);
        $this->evaluate($card, '2026-10-02 12:02:00');

        self::assertSame(1, $this->ruleState($card, 'draft')->fires);
    }

    public function test_a_synced_pull_request_that_is_behind_again_on_a_new_head_syncs_again(): void
    {
        [$card, $pullRequest, $writer] = $this->behindCard();
        $this->evaluate($card);

        $pullRequest->headSha = self::HEAD_TWO;
        $pullRequest->syncFromSha = $pullRequest->syncRequestedAt = null;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame([['update', $pullRequest->number, self::HEAD_ONE], ['update', $pullRequest->number, self::HEAD_TWO]], $writer->calls);
        self::assertSame([2, 0], [$this->ruleState($card, 'update-behind')->fires, $this->ruleState($card, 'update-behind')->attempts]);
    }

    public function test_a_behind_pull_request_that_nothing_moves_syncs_once(): void
    {
        [$card, $pullRequest, $writer] = $this->behindCard();

        $this->evaluate($card);
        $this->evaluate($card, '2026-10-02 12:01:00');
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame([['update', $pullRequest->number, self::HEAD_ONE]], $writer->calls);
        self::assertSame(1, $this->ruleState($card, 'update-behind')->fires);
    }

    public function test_a_refused_sync_backs_off_and_does_not_refire_on_the_same_head(): void
    {
        [$card, , $writer] = $this->behindCard();
        $writer->failure = new PullRequestSyncFailed('refused', permanent: true);

        $this->evaluate($card);
        self::assertSame([1, '2026-10-02 12:10:00'], [$this->ruleState($card, 'update-behind')->attempts, $this->ruleState($card, 'update-behind')->dueAt?->format('Y-m-d H:i:s')]);

        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertCount(1, $writer->calls);
        self::assertSame(1, $this->ruleState($card, 'update-behind')->attempts);

        $this->evaluate($card, '2026-10-02 12:10:00');
        self::assertCount(2, $writer->calls);
        self::assertSame(2, $this->ruleState($card, 'update-behind')->attempts);
    }

    public function test_a_refire_while_the_sync_is_in_flight_is_done_with_no_attempt_and_no_second_write(): void
    {
        [$card, $pullRequest, $writer] = $this->behindCard();
        $this->evaluate($card);

        $pullRequest->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertCount(1, $writer->calls);
        self::assertSame([2, 0], [$this->ruleState($card, 'update-behind')->fires, $this->ruleState($card, 'update-behind')->attempts]);
        self::assertNull($this->activePause($card));
    }

    public function test_a_new_head_does_not_refire_a_rule_that_does_not_read_behind(): void
    {
        [$card, $pullRequest] = $this->behindCard([['id' => 'comment', 'when' => ['pr.open' => []], 'then' => ['forge-write' => ['write' => 'comment', 'comment' => 'fix-run']]]]);
        $this->evaluate($card);

        $pullRequest->headSha = self::HEAD_TWO;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame(1, $this->ruleState($card, 'comment')->fires);
    }

    public function test_a_new_head_pushed_before_the_next_evaluation_gets_its_own_stale_approval_notice(): void
    {
        $card = $this->boundCard([['id' => 'comment-stale-approval', 'when' => ['card.pr.approval_stale' => []], 'then' => ['forge-write' => ['write' => 'comment', 'comment' => 'stale-approval']]]]);
        $pullRequest = $this->pullRequest($card, headSha: 'head1');
        $pullRequest->approvalId = 'review-1';
        $pullRequest->coveredSha = 'approved';
        $pullRequest->uncoveredSha = 'head1';
        $this->em()->flush();

        $this->evaluate($card);
        self::assertCount(1, $this->service(PullRequestNoticeRepository::class)->findBy(['forgePullRequestId' => $pullRequest->id]));

        $pullRequest->headSha = $pullRequest->uncoveredSha = 'head2';
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:01:00');

        $keys = array_map(static fn (PullRequestNotice $notice): string => $notice->noticeKey, $this->service(PullRequestNoticeRepository::class)->findBy(['forgePullRequestId' => $pullRequest->id], ['noticeKey' => 'ASC']));
        self::assertSame([StaleApprovalNoticeBody::key('head1'), StaleApprovalNoticeBody::key('head2')], $keys);
    }

    public function test_an_agent_review_with_findings_and_a_new_check_gets_one_summary_notice(): void
    {
        $card = $this->boundCard([['id' => 'agent-review-summary', 'when' => ['agent_review.uncommented' => []], 'then' => ['forge-write' => ['write' => 'comment', 'comment' => 'agent-review']]]]);
        $pullRequest = $this->pullRequest($card, headSha: 'head1');
        $review = new AgentReview($card->project, $card, $pullRequest, 'head1', 'Review.', AgentReviewConclusion::Failure, [new AgentReviewFinding('src/A.php', 1, 2, AgentReviewSeverity::Important, 'Null read', 'Body.')]);
        $review->postedAt = new \DateTimeImmutable();
        $this->em()->persist($review);
        $this->em()->flush();

        $this->evaluate($card);
        $this->evaluate($card, '2026-10-02 12:01:00');

        $keys = array_map(static fn (PullRequestNotice $notice): string => $notice->noticeKey, $this->service(PullRequestNoticeRepository::class)->findBy(['forgePullRequestId' => $pullRequest->id]));
        self::assertSame([AgentReviewNoticeBody::key($review->id ?? throw new \LogicException('A flushed review has an id.'))], $keys);
        self::assertSame([], $this->service(UncommentedAgentReviews::class)->among([$review]));
    }

    public function test_a_second_fix_run_queued_before_the_next_evaluation_gets_its_own_comment(): void
    {
        $card = $this->boundCard([['id' => 'comment-fix-run', 'when' => ['card.fix_run.uncommented' => []], 'then' => ['forge-write' => ['write' => 'comment', 'comment' => 'fix-run']]]]);
        $this->pullRequest($card);
        $first = $this->workerRun($card, 'fix', WorkerRunState::Queued, role: 'fix');

        $this->evaluate($card);
        self::assertCount(1, $this->service(PullRequestCommentRepository::class)->findBy(['cardId' => $card->id]));

        $second = $this->workerRun($card, 'fix', WorkerRunState::Queued, role: 'fix');
        $this->evaluate($card, '2026-10-02 12:01:00');

        $runIds = array_map(static fn (PullRequestComment $comment): string => (string) $comment->runId, $this->service(PullRequestCommentRepository::class)->findBy(['cardId' => $card->id]));
        sort($runIds);
        $expected = [(string) $first->id, (string) $second->id];
        sort($expected);
        self::assertSame($expected, $runIds);
    }

    /**
     * Binds a template whose slot "one" is the column "next", "two" is "in-progress" and "three" has no column.
     *
     * @param list<array<string, mixed>> $rules
     * @param list<int>                  $backoffMinutes
     * @param array<string, mixed>       $onWorkFailed
     */
    private function boundCard(array $rules, array $backoffMinutes = [10, 60], array $onWorkFailed = []): Card
    {
        self::bootKernel();
        $project = $this->workflowProject('engine');
        $this->em()->persist(new WorkflowBinding($project, 'test', 1, [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [
                ['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime'],
                ['key' => 'bug', 'label' => 'board.card.type.bug', 'tone' => 'amber'],
                ['key' => 'security', 'label' => 'board.card.type.security', 'tone' => 'red'],
                ['key' => 'tooling', 'label' => 'board.card.type.tooling', 'tone' => 'neutral'],
                ['key' => 'docs', 'label' => 'board.card.type.docs', 'tone' => 'green'],
                ['key' => 'idea', 'label' => 'board.card.type.idea', 'tone' => 'purple'],
                ['key' => 'epic', 'label' => 'board.card.type.epic', 'tone' => 'blue', 'capabilities' => ['children', 'lane']],
            ],
            'slots' => [['key' => 'one', 'label' => 'one'], ['key' => 'two', 'label' => 'two'], ['key' => 'three', 'label' => 'three']],
            'manualMoves' => [],
            'backoffMinutes' => $backoffMinutes,
            ...([] === $onWorkFailed ? [] : ['onWorkFailed' => $onWorkFailed]),
            'rules' => $rules,
        ]));
        $this->em()->persist(new WorkflowSlotLink($project, 'one', $this->column($project, 'next')->id));
        $this->em()->persist(new WorkflowSlotLink($project, 'two', $this->column($project, 'in-progress')->id));
        $this->em()->flush();

        return $this->card($project, 'next');
    }

    /**
     * A card whose approved pull request is behind on HEAD_ONE, with every branch update recorded by a fake forge writer.
     *
     * @param list<array<string, mixed>>|null $rules the rules, or the sync rule alone when null
     *
     * @return array{Card, ForgePullRequest, FakeForgeWriter}
     */
    private function behindCard(?array $rules = null): array
    {
        $card = $this->boundCard($rules ?? [['id' => 'update-behind', 'when' => ['pr.behind' => []], 'then' => ['forge-write' => ['write' => 'update-branch', 'fallback' => 'sync']]]]);
        $writer = new FakeForgeWriter();
        self::getContainer()->set(ForgePullRequestWrites::class, new ForgePullRequestWrites(
            new PullRequestMergers([$writer]),
            new PullRequestBaseChangers([$writer]),
            new PullRequestBranchUpdaters([$writer]),
            $this->service(ForgePullRequestRepository::class),
            $this->em(),
            new MockClock(self::NOON),
            $this->service(MessageBusInterface::class),
        ));
        $pullRequest = $this->pullRequest($card, headSha: self::HEAD_ONE);
        $pullRequest->mergeability = PullRequestMergeability::Behind;
        $this->em()->flush();

        return [$card, $pullRequest, $writer];
    }

    /** Rule "review" holds a live fix, and rule "checks" of the same kind turns true while it runs. */
    private function joinedCard(): Card
    {
        $card = $this->boundCard([
            self::requestRule('review', self::ALWAYS, kind: 'fix'),
            self::requestRule('checks', ['card.type' => ['types' => ['bug']]], kind: 'fix'),
        ]);
        $this->evaluate($card);
        $review = $this->liveRequestOfKind($card, 'fix');
        self::assertSame('review', $review->ruleId);

        $this->setType($card, 'bug');
        $this->evaluate($card, '2026-10-02 12:05:00');
        $this->evaluate($card, '2026-10-02 12:06:00');

        self::assertSame([$review], $this->liveRequests($card));
        self::assertSame(0, $this->ruleState($card, 'checks')->fires);

        return $card;
    }

    /**
     * @param array<string, mixed> $when
     *
     * @return array<string, mixed>
     */
    private static function requestRule(string $id, array $when, ?int $limit = null, bool $refill = false, ?string $kind = null, ?string $role = null): array
    {
        $request = ['kind' => $kind ?? $id];
        if (null !== $role) {
            $request['role'] = $role;
        }
        if (null !== $limit) {
            $request['limit'] = $limit;
        }
        if ($refill) {
            $request['refill'] = ['all' => [['pr.checks_passed' => []], ['not' => ['pr.conflicting' => []]], ['not' => ['pr.changes_requested' => []]]]];
        }

        return ['id' => $id, 'slot' => 'one', 'when' => $when, 'then' => ['request' => $request]];
    }

    /**
     * @param array<string, mixed> $when
     *
     * @return array<string, mixed>
     */
    private static function askRule(string $id, array $when): array
    {
        return ['id' => $id, 'slot' => 'one', 'when' => $when, 'then' => ['ask' => [
            'question' => 'Question',
            'options' => [['label' => 'Detach', 'then' => [['detach' => []]]]],
        ]]];
    }

    /**
     * @param array<string, mixed> $when
     *
     * @return array<string, mixed>
     */
    private static function moveRule(string $id, string $to, array $when): array
    {
        return ['id' => $id, 'slot' => 'one', 'when' => $when, 'then' => ['move' => ['to' => $to]]];
    }

    /**
     * @param list<array<string, mixed>> $then
     * @param array<string, mixed>       $when
     *
     * @return array<string, mixed>
     */
    private static function listRule(string $id, array $then, array $when = self::NOT_EPIC): array
    {
        return ['id' => $id, 'slot' => 'one', 'when' => $when, 'then' => $then];
    }

    /** @return array<string, mixed> */
    private static function listed(string $label): array
    {
        return ['listed-test' => ['label' => $label]];
    }

    /** @return list<array<string, mixed>> */
    private function firedRecords(): array
    {
        return array_values(array_filter($this->logger->records, static fn (array $record): bool => 'workflow.card_evaluated' === $record['message']));
    }

    /** @return list<string> the ids of the rules that fired in the last evaluation */
    private function firedRules(): array
    {
        $records = $this->firedRecords();
        self::assertNotSame([], $records);
        $fired = $records[\count($records) - 1]['context']['fired'] ?? null;
        self::assertIsArray($fired);

        $rules = [];
        foreach ($fired as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['rule'] ?? null);
            $rules[] = $entry['rule'];
        }

        return $rules;
    }

    /** @return list<array<string, mixed>> */
    private function errors(): array
    {
        return array_values(array_filter($this->logger->records, static fn (array $record): bool => LogLevel::ERROR === $record['level']));
    }

    private function provider(): ProvidedFactsProvider
    {
        return $this->service(ProvidedFactsProvider::class);
    }

    private function providers(): FactProviders
    {
        return new FactProviders([...array_values(array_diff_key($this->service(FactProviders::class)->byClass, [ProvidedFacts::class => true])), $this->provider()]);
    }

    private function evaluate(Card $card, string $at = self::NOON): void
    {
        $this->engine()->evaluate($card->id ?? throw new \LogicException('A flushed card has an id.'), new \DateTimeImmutable($at));
    }

    private function engine(): Engine
    {
        $clock = new MockClock(self::NOON);
        $auditor = $this->service(Auditor::class);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $cardPauses = $this->service(CardPauseRepository::class);
        $workRequests = $this->service(WorkRequestRepository::class);
        $releaseCardPause = new ReleaseCardPauseHandler($this->em(), $clock, $auditor, $dispatcher);
        $boardPauses = new BoardCardPauses(
            $this->service(CardRepository::class),
            $cardPauses,
            $this->service(CardEventRepository::class),
            new PauseCardHandler($cardPauses, $this->em(), $clock, $auditor, $dispatcher, $this->service(CardEventRepository::class)),
            $releaseCardPause,
            $this->em(),
        );
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $settling = new SettlingWorkRequestRepository($doctrine);
        $settling->settle = $this->settleJoinedWork;
        $opener = new WorkRequestOpener(
            new OpenWorkRequestHandler($settling, $this->service(OutboxWriter::class), $this->em(), new MockClock('2026-10-02 12:00:00'), $this->service(Auditor::class), $this->service(WorkRequestAnnouncer::class), $this->service(WorkerRunRepository::class), new WorkSubjectHandlers([]), $this->service(WorkHolds::class)),
            $this->service(CardRepository::class),
            new WorkRequestContexts($this->service(CardPullRequests::class), $this->service(CardPullRequestRepository::class), $this->service(EpicBranches::class)),
            $this->service(RetryNotes::class),
            $this->service(KindPrompts::class),
        );
        $forgePullRequests = $this->service(ForgePullRequestRepository::class);

        $engineEvents = new EventDispatcher();
        $engineEvents->addListener(CardPaused::class, function (CardPaused $event): void {
            $this->paused[] = $event;
        });
        $engineEvents->addListener(BlockerHoldChanged::class, function (BlockerHoldChanged $event): void {
            $this->changed[] = $event;
        });

        $actions = new Actions([
            new MoveCard($this->service(CardRepository::class), $this->service(BoardColumnRepository::class), $this->service(UpdateCardHandler::class)),
            new RequestWork($this->service(CardRepository::class), $opener, $this->service(CardPullRequests::class), $this->service(CardEventRepository::class), $this->workHolds),
            new PauseCard(),
            new ReleasePause($boardPauses),
            $this->service(ForgeWrite::class),
            new Ask($this->asks, $this->service(TranslatorInterface::class), 'en'),
            new EvaluateChildren($this->service(CardDirectory::class), $this->evaluations ?? $this->service(EvaluationTrigger::class)),
            new MissingAction(),
            new ListedTestAction(),
        ]);

        return new Engine(
            $this->em(),
            $this->service(CardDirectory::class),
            $this->service(ProjectRepository::class),
            new ProjectTemplateCopy($this->service(WorkflowBindingRepository::class), new TemplateParser($this->service(Conditions::class), $this->parsingActions(), $this->service(TemplatePrompts::class), $this->service(TemplateMetricParser::class)), $this->appRules ?? $this->service(AppRules::class)),
            new FactsBuilder(
                $this->service(WorkflowSlotLinkRepository::class),
                $this->service(CardDirectory::class),
                $this->service(ProjectRepository::class),
                $this->providers(),
                $this->em()->getConnection(),
                $this->service(PassMemo::class),
            ),
            new FactFingerprint(),
            $this->service(WorkflowRuleStateRepository::class),
            new BridgeWorkLedger(
                $workRequests,
                $this->service(WorkerRunRepository::class),
                new WithdrawWorkRequestHandler($workRequests, $this->service(OutboxWriter::class), $this->em(), $clock, $auditor, $this->service(WorkRequestAnnouncer::class), new WorkSubjectHandlers([]), $this->service(WorkHolds::class)),
                $this->service(CardHolds::class),
                $this->service(ProjectRepository::class),
                $this->service(WorkTakers::class),
            ),
            $this->service(WorkflowAutomation::class),
            $this->service(WorkflowPendingBaselineRepository::class),
            $boardPauses,
            $actions,
            $opener,
            $this->contexts($actions),
            new RuleSubject(),
            new MarkerPass(new RuleSubject(), $this->service(CardMarkerStore::class)),
            $this->asks,
            $this->workHolds,
            $engineEvents,
            $this->logger,
        );
    }

    /** The actions of the container and the listed test action, so a stored rule can name any of them. */
    private function parsingActions(): Actions
    {
        $actions = $this->service(Actions::class);

        return new Actions([...array_map($actions->get(...), $actions->keys()), new ListedTestAction()]);
    }

    /** @param array<string, mixed> $onWorkFailed */
    private function stoppedCard(string $code, array $onWorkFailed = ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]): Card
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: $onWorkFailed);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], $code, '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        self::assertNotNull($this->activePause($card));

        return $card;
    }

    /**
     * Rules "a" and "b" ask for the same kind of work. Rule "a" holds a settled request, or a stop that pauses the card.
     * Rule "b" holds a request of its own that a worker refused as needs-person at 12:20, and its state has not read it yet.
     *
     * @param array<string, mixed> $onWorkFailed
     * @param array<string, mixed> $ruleBWhen
     */
    private function twoRuleCard(?string $stopOfRuleA = null, bool $stopOfRuleB = true, array $onWorkFailed = self::RETRY_ONCE, array $ruleBWhen = self::ALWAYS): Card
    {
        $card = $this->boundCard([
            self::requestRule('a', self::ALWAYS, kind: 'work'),
            self::requestRule('b', $ruleBWhen, kind: 'work'),
        ], onWorkFailed: $onWorkFailed);
        $this->evaluate($card);
        $first = $this->ruleRequest($card, 'a');
        if (null === $stopOfRuleA) {
            $this->finish($first, '2026-10-02 12:10:00');
        } else {
            $this->refuse($first, $stopOfRuleA, '2026-10-02 12:10:00');
            $this->evaluate($card, '2026-10-02 12:15:00');
            self::assertSame('a', $this->activePause($card)?->ruleId);
        }

        $own = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, 'work', null, 'b', new \DateTimeImmutable('2026-10-02 12:15:00'));
        $this->em()->persist($own);
        $this->em()->flush();
        $state = $this->ruleState($card, 'b');
        $state->truth = true;
        $state->workRequestId = $own->id;
        $this->em()->flush();
        if ($stopOfRuleB) {
            $this->refuse($own, 'needs-person', '2026-10-02 12:20:00');
        }

        return $card;
    }

    private function ruleRequest(Card $card, string $ruleId): WorkRequest
    {
        $id = $this->ruleState($card, $ruleId)->workRequestId ?? throw new \LogicException('The rule holds no request.');

        return $this->service(WorkRequestRepository::class)->find($id) ?? throw new \LogicException('The request is stored.');
    }

    private function resumedRun(Card $card, WorkerRunState $state, ?\DateTimeImmutable $receivedAt = null, ?string $ruleId = null): WorkerRun
    {
        return $this->workerRun($card, 'work', $state, $this->workerRun($card, 'work', WorkerRunState::Blocked), $receivedAt, $ruleId);
    }

    /**
     * A request rule that waits while a session is open, and withdraws its request when one opens.
     *
     * @return array<string, mixed>
     */
    private function sessionRule(bool $openOnly): array
    {
        $rule = self::requestRule('work', ['not' => ['card.run.session_open' => []]]);
        $rule['then']['request']['cancelWhen'] = ['card.run.session_open' => []];
        if ($openOnly) {
            $rule['then']['request']['cancelOnly'] = 'open';
        }

        return $rule;
    }

    private function interactiveRun(Card $card): WorkerRun
    {
        $run = new WorkerRun(
            project: $card->project,
            bridgeId: null,
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: $card->number,
            workKind: 'product-design',
            state: WorkerRunState::Running,
            sessionId: Uuid::v7(),
            startedAt: new \DateTimeImmutable('2026-10-02 12:01:00'),
            kind: WorkerRunKind::Interactive,
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }

    private function workerRun(Card $card, string $workKind, WorkerRunState $state, ?WorkerRun $continues = null, ?\DateTimeImmutable $receivedAt = null, ?string $ruleId = null, ?string $role = null): WorkerRun
    {
        $run = new WorkerRun(
            project: $card->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: $card->number,
            workKind: $workKind,
            state: $state,
            receivedAt: $receivedAt ?? new \DateTimeImmutable(),
            continuesRun: $continues,
            ruleId: $ruleId,
            role: $role,
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }

    /** Runs the queued evaluations of the card, then empties the queue. Answers how many ran. */
    private function evaluateQueued(Card $card, string $at): int
    {
        $transport = self::getContainer()->get('messenger.transport.card_eval');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $cardId = $card->id ?? throw new \LogicException('A flushed card has an id.');
        $evaluations = 0;
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EvaluateCard && $message->cardId === $cardId->toRfc4122()) {
                $this->evaluate($card, $at);
                ++$evaluations;
            }
        }
        $transport->reset();

        return $evaluations;
    }

    private function ruleState(Card $card, string $ruleId): WorkflowRuleState
    {
        return $this->ruleStateOrNull($card, $ruleId) ?? throw new \LogicException(\sprintf('The rule "%s" has no state.', $ruleId));
    }

    private function ruleStateOrNull(Card $card, string $ruleId): ?WorkflowRuleState
    {
        return $this->service(WorkflowRuleStateRepository::class)->findForCard($card->snapshot()->id)[$ruleId] ?? null;
    }

    /** @return list<WorkRequest> */
    private function liveRequests(Card $card): array
    {
        return $this->service(WorkRequestRepository::class)->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.'));
    }

    private function liveRequestOfKind(Card $card, string $kind): WorkRequest
    {
        return array_find($this->liveRequests($card), static fn (WorkRequest $request): bool => $kind === $request->kind)
            ?? throw new \LogicException(\sprintf('The card has no live request of the kind "%s".', $kind));
    }

    private function activePause(Card $card): ?CardPause
    {
        return $this->service(CardPauseRepository::class)->findActiveForCard($card);
    }

    /** A request that appears after the hold, which the hold itself could not cancel. */
    private function openRequestWhileHeld(Card $card): WorkRequest
    {
        $request = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, 'work', null, 'work', new \DateTimeImmutable(self::NOON));
        $this->em()->persist($request);
        $this->em()->flush();

        return $request;
    }

    private function hold(Card $card): void
    {
        $this->service(CardHolds::class)->hold($card->project, $card->id ?? throw new \LogicException('A flushed card has an id.'), null);
    }

    /** The hold goes through the listener. The engine shares the entity manager, so the rows the listener rewrote are read again. */
    private function releaseHold(Card $card, string $at = self::NOON): void
    {
        $clock = new MockClock($at);
        $events = new EventDispatcher();
        $events->addListener(CardHoldsReleased::class, new RearmCardsOnCardHoldsReleased(
            $this->service(WorkRequestRepository::class),
            $this->service(WorkflowRuleStates::class),
            $this->service(EvaluationTrigger::class),
            $clock,
        ));
        $holds = new CardHolds($this->service(CardHoldRepository::class), $this->em(), $clock, $events);

        $holds->release($card->project, [$card->id ?? throw new \LogicException('A flushed card has an id.')]);
        foreach ([...$this->service(WorkflowRuleStateRepository::class)->findForCard($card->snapshot()->id), ...$this->liveRequests($card)] as $row) {
            $this->em()->refresh($row);
        }
    }

    /** The hold goes with no listener, so the rules keep their state. */
    private function dropHold(Card $card): void
    {
        $this->service(CardHoldRepository::class)->deleteOfCards($card->project, [$card->id ?? throw new \LogicException('A flushed card has an id.')]);
    }

    private function saveAutomation(Card $card, bool $enabled): void
    {
        $this->service(SaveBoardAutomationSettingsHandler::class)(new SaveBoardAutomationSettingsCommand(
            $card->project,
            $enabled,
        ));
    }

    private function requestChanges(ForgePullRequest $pullRequest, string $at = '2026-10-02 11:55:00'): void
    {
        $pullRequest->review = PullRequestReview::ChangesRequested;
        $pullRequest->changesRequestedAt = new \DateTimeImmutable($at);
        $this->em()->flush();
    }

    private function releaseByPerson(Card $card): void
    {
        $this->service(ReleaseWorkflowPauseHandler::class)(new ReleaseWorkflowPauseCommand($card->snapshot(), $card->project->owner->id ?? throw new \LogicException('The owner is persisted.'), Actor::Human, null));
    }

    private function refuse(WorkRequest $request, string $code, string $at): void
    {
        $request->state = WorkRequestState::Claimed;
        $request->settle(WorkRequestState::Refused, $code, new \DateTimeImmutable($at));
        $this->em()->flush();
    }

    private function finish(WorkRequest $request, string $at = '2026-10-02 12:20:00'): void
    {
        $request->state = WorkRequestState::Claimed;
        $request->settle(WorkRequestState::Done, null, new \DateTimeImmutable($at));
        $this->em()->flush();
    }

    /** Refuses the request of the rule "work" and its one retry, so the engine opens the repair request at 12:41. */
    private function repairing(Card $card): WorkRequest
    {
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $this->evaluate($card, '2026-10-02 12:31:00');
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:40:00');
        $this->evaluate($card, '2026-10-02 12:41:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);

        return $live[0];
    }

    private function epic(Project $project): Card
    {
        $epic = $this->card($project, 'in-progress');
        $this->setType($epic, 'epic');

        return $epic;
    }

    private function epicInReview(string $slug): Card
    {
        self::bootKernel();
        $project = $this->workflowProject($slug);
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->childOf($epic, 'done');
        $this->moveTo($epic, 'in-review');

        return $epic;
    }

    /** @return list<string> */
    private function liveKinds(Card $card): array
    {
        return array_map(static fn (WorkRequest $request): string => $request->kind, $this->liveRequests($card));
    }

    private function childOf(Card $epic, string $column): Card
    {
        $child = $this->card($epic->project, $column);
        $child->parent = $epic;
        $this->em()->flush();

        return $child;
    }

    private function finishRequests(Card $card): void
    {
        foreach ($this->liveRequests($card) as $request) {
            $request->state = WorkRequestState::Claimed;
            $request->settle(WorkRequestState::Done, null, new \DateTimeImmutable(self::NOON));
        }
        $this->em()->flush();
    }

    private function workRequest(Card $card, string $kind): WorkRequest
    {
        $request = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, $kind, null, $kind, new \DateTimeImmutable(self::NOON));
        $this->em()->persist($request);
        $this->em()->flush();

        return $request;
    }

    private function setType(Card $card, string $type): void
    {
        $card->type = $type;
        $this->em()->flush();
    }

    private function moveTo(Card $card, string $slug): void
    {
        $card->column = $this->column($card->project, $slug);
        $this->em()->flush();
    }

    private function document(Card $card, string $tagName, DocumentStatus $status = DocumentStatus::Approved): Document
    {
        $document = new Document($card->project->owner, $card->project, 'Design');
        $document->status = $status;
        $tag = new Tag($card->project, $tagName);
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return $document;
    }

    private function setStatus(Document $document, DocumentStatus $status): void
    {
        $document->status = $status;
        $this->em()->flush();
    }

    private function block(Card $card): void
    {
        $this->em()->persist(new CardLink($this->card($card->project, 'next'), $card, CardLinkKind::Blocks));
        $this->em()->flush();
    }

    /** @return list<string> the rule ids of every work request of the card, live or settled */
    private function requestRuleIds(Card $card): array
    {
        return array_map(
            static fn (WorkRequest $request): string => $request->ruleId,
            $this->service(WorkRequestRepository::class)->findBy(['subjectType' => WorkSubject::CARD, 'subjectId' => $card->id]),
        );
    }
}
