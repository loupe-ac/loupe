<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Workflow\WorkRequestContexts;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Bridge\Repository\WorkerRunOutputChunkRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\View\WorkerRunControls;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\AskingBridge;
use App\Module\Inbox\Service\CardWaitText;
use App\Module\Inbox\Service\InboxReviewLookup;
use App\Module\Inbox\Service\ItemActions;
use App\Module\Inbox\Service\RunCommandResolver;
use App\Module\Inbox\Service\RunCommandRuns;
use App\Module\Inbox\Service\SessionBridges;
use App\Module\Inbox\Service\SessionPromptWriter;
use App\Module\Inbox\View\ItemAction;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\StageWorks;
use App\Module\Workflow\Service\TemplateStageWorks;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ItemActionsTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Project $project;
    private Card $card;
    private Bridge $bridge;
    private int $number = 0;
    private ?InboxCardWatch $watch = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->project($em, $this->owner($em, 'item-actions'), 'item-actions');
        $this->card = $this->card($em, $this->project, 7);
        $this->bridge = new Bridge($this->project->owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $em->persist($this->bridge);
        $em->flush();
    }

    public function test_a_resumable_run_wait_offers_resume_as_the_default(): void
    {
        $item = $this->waitItem([$this->runWait($this->workerRun(WorkerRunState::Blocked))]);

        self::assertSame(
            [[InboxActionKind::ResumeRun, true], [InboxActionKind::Open, false], [InboxActionKind::Dismiss, false]],
            $this->summary($item),
        );
    }

    public function test_the_labels_are_translation_keys_of_the_kind(): void
    {
        $actions = $this->actions()->forItem($this->waitItem([$this->runWait($this->workerRun(WorkerRunState::Blocked))]));

        self::assertSame('inbox.action.resume-run.label', $actions[0]->labelKey);
        self::assertSame('inbox.action.resume-run.effect', $actions[0]->effectKey);
        self::assertNull($actions[0]->disabledReasonKey);
    }

    public function test_a_running_run_does_not_offer_resume(): void
    {
        $item = $this->waitItem([$this->runWait($this->workerRun(WorkerRunState::Running))]);

        self::assertSame([[InboxActionKind::Open, true], [InboxActionKind::Dismiss, false]], $this->summary($item));
    }

    public function test_a_run_with_a_pending_command_does_not_offer_resume(): void
    {
        $run = $this->workerRun(WorkerRunState::Blocked);
        $this->em->persist(new BridgeCommand(
            owner: $this->project->owner,
            bridgeId: $this->bridge->id,
            project: $this->project,
            workerRun: $run,
            kind: BridgeCommandKind::ResumeRun,
            requestedBy: null,
            requestedAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+15 minutes'),
        ));
        $this->em->flush();

        self::assertSame([[InboxActionKind::Open, true], [InboxActionKind::Dismiss, false]], $this->summary($this->waitItem([$this->runWait($run)])));
    }

    public function test_a_bridge_that_takes_no_commands_does_not_offer_resume(): void
    {
        $this->bridge->capabilities = [];
        $item = $this->waitItem([$this->runWait($this->workerRun(WorkerRunState::Blocked))]);

        self::assertSame([[InboxActionKind::Open, true], [InboxActionKind::Dismiss, false]], $this->summary($item));
    }

    public function test_an_ended_wait_leaves_open_as_the_default(): void
    {
        $wait = $this->runWait($this->workerRun(WorkerRunState::Blocked));
        $wait->endedAt = new \DateTimeImmutable();

        self::assertSame([[InboxActionKind::Open, true], [InboxActionKind::Dismiss, false]], $this->summary($this->waitItem([$wait])));
    }

    #[DataProvider('releasableKinds')]
    public function test_a_releasable_pause_offers_retry_as_the_default(PauseKind $kind): void
    {
        $item = $this->waitItem([$this->pauseWait($kind)]);

        self::assertSame(
            [[InboxActionKind::RetryCard, true], [InboxActionKind::Open, false], [InboxActionKind::Dismiss, false]],
            $this->summary($item),
        );
    }

    /** @return iterable<string, array{PauseKind}> */
    public static function releasableKinds(): iterable
    {
        yield 'retries' => [PauseKind::Retries];
        yield 'work timeout' => [PauseKind::WorkTimeout];
        yield 'work stopped' => [PauseKind::WorkStopped];
        yield 'work limit' => [PauseKind::WorkLimit];
    }

    public function test_a_rule_pause_offers_open_as_the_default_and_no_retry(): void
    {
        $item = $this->waitItem([$this->pauseWait(PauseKind::Rule)]);

        self::assertSame([[InboxActionKind::Open, true], [InboxActionKind::Dismiss, false]], $this->summary($item));
    }

    public function test_a_wait_for_a_pause_that_is_no_longer_active_offers_no_retry(): void
    {
        $this->pauseWait(PauseKind::Retries);
        $stale = new InboxCardWait($this->watchOf(), 'paused', InboxCardWaitType::CardPause, 'marker.paused', pauseId: Uuid::v4());

        self::assertSame([[InboxActionKind::Open, true], [InboxActionKind::Dismiss, false]], $this->summary($this->waitItem([$stale])));
    }

    public function test_a_card_wait_offers_open_and_dismiss_only(): void
    {
        $watch = $this->watchOf();
        $spell = new \DateTimeImmutable('2026-10-13 09:00:00', new \DateTimeZone('UTC'));
        $watch->waits->add(InboxCardWait::forSubject($watch, 'nothing-happened', 'marker.nothing_happened', [], MarkerSubject::card($watch->cardId, $spell), $spell));

        self::assertSame([[InboxActionKind::Open, true], [InboxActionKind::Dismiss, false]], $this->summary($watch->item));
    }

    public function test_a_document_wait_offers_review_as_the_default(): void
    {
        $item = $this->waitItem([$this->documentWait()]);

        self::assertSame(
            [[InboxActionKind::Review, true], [InboxActionKind::Open, false], [InboxActionKind::Dismiss, false]],
            $this->summary($item),
        );
    }

    public function test_a_pull_request_wait_offers_review_as_the_default(): void
    {
        $item = $this->waitItem([$this->pullRequestWait()]);

        self::assertSame(
            [[InboxActionKind::Review, true], [InboxActionKind::Open, false], [InboxActionKind::Dismiss, false]],
            $this->summary($item),
        );
    }

    public function test_the_newest_wait_sets_the_default(): void
    {
        $item = $this->waitItem([
            $this->runWait($this->workerRun(WorkerRunState::Blocked), new \DateTimeImmutable('2026-10-13 10:00:00')),
            $this->documentWait(new \DateTimeImmutable('2026-10-13 10:00:05')),
        ]);

        self::assertSame(
            [[InboxActionKind::ResumeRun, false], [InboxActionKind::Review, true], [InboxActionKind::Open, false], [InboxActionKind::Dismiss, false]],
            $this->summary($item),
        );
    }

    public function test_a_tie_on_the_start_time_goes_to_the_greater_wait_key(): void
    {
        $at = new \DateTimeImmutable('2026-10-13 10:00:00');
        $run = $this->runWait($this->workerRun(WorkerRunState::Blocked), $at);
        $document = $this->documentWait($at);
        self::assertGreaterThan($document->key(), $run->key());

        $forward = $this->waitItem([$run, $document]);

        self::assertSame([InboxActionKind::ResumeRun], $this->defaults($forward));
    }

    public function test_the_order_of_the_waits_does_not_change_the_default(): void
    {
        $at = new \DateTimeImmutable('2026-10-13 10:00:00');
        $item = $this->waitItem([$this->documentWait($at), $this->runWait($this->workerRun(WorkerRunState::Blocked), $at)]);

        self::assertSame([InboxActionKind::ResumeRun], $this->defaults($item));
    }

    public function test_open_is_the_default_when_the_kind_of_the_newest_wait_does_not_fit(): void
    {
        $item = $this->waitItem([
            $this->documentWait(new \DateTimeImmutable('2026-10-13 10:00:00')),
            $this->runWait($this->workerRun(WorkerRunState::Running), new \DateTimeImmutable('2026-10-13 10:00:05')),
        ]);

        self::assertSame(
            [[InboxActionKind::Review, false], [InboxActionKind::Open, true], [InboxActionKind::Dismiss, false]],
            $this->summary($item),
        );
    }

    public function test_the_list_follows_the_menu_order_and_never_holds_a_later_kind(): void
    {
        $item = $this->waitItem([
            $this->runWait($this->workerRun(WorkerRunState::Blocked), new \DateTimeImmutable('2026-10-13 10:00:00')),
            $this->documentWait(new \DateTimeImmutable('2026-10-13 10:00:01')),
            $this->pauseWait(PauseKind::Retries, new \DateTimeImmutable('2026-10-13 10:00:02')),
        ]);

        $kinds = array_map(static fn (ItemAction $action): InboxActionKind => $action->kind, $this->actions()->forItem($item));

        self::assertSame([InboxActionKind::ResumeRun, InboxActionKind::RetryCard, InboxActionKind::Review, InboxActionKind::Open, InboxActionKind::StartSession, InboxActionKind::Dismiss], $kinds);
        self::assertSame([InboxActionKind::RetryCard], $this->defaults($item));
    }

    public function test_a_question_that_links_a_card_offers_no_action_the_agent_did_not_suggest_beside_its_response(): void
    {
        $item = $this->item($this->em, $this->project);
        $item->cards->add(new InboxItemCard($item, $this->card));
        $this->em->flush();

        self::assertSame([InboxActionKind::Answer, InboxActionKind::Dismiss], $this->kinds($item));
    }

    public function test_a_question_offers_only_the_suggestions_that_fit_and_defaults_to_the_recommended_one(): void
    {
        $item = $this->questionSuggesting([
            ['action' => 'open', 'reason' => 'Read the card first.', 'recommended' => true, 'cardId' => null],
            ['action' => 'retry-card', 'reason' => 'Retry it.', 'recommended' => false, 'cardId' => null],
        ]);

        self::assertSame(
            [[InboxActionKind::Answer, false, null], [InboxActionKind::Open, true, 'Read the card first.'], [InboxActionKind::Dismiss, false, null]],
            array_map(static fn (ItemAction $action): array => [$action->kind, $action->default, $action->reason], $this->actions()->forItem($item)),
        );
    }

    public function test_a_question_without_a_recommendation_defaults_to_its_answer(): void
    {
        $item = $this->questionSuggesting([['action' => 'open', 'reason' => 'Read the card first.', 'recommended' => false, 'cardId' => null]]);

        self::assertSame([InboxActionKind::Answer], $this->defaults($item));
        self::assertSame([InboxActionKind::Answer, InboxActionKind::Open, InboxActionKind::Dismiss], $this->kinds($item));
    }

    public function test_an_agent_cannot_suggest_a_response_kind(): void
    {
        foreach ([InboxActionKind::Answer, InboxActionKind::Complete, InboxActionKind::SubmitReview] as $kind) {
            self::assertFalse($kind->isSuggestable());
            self::assertTrue($kind->isResponse());
        }
        self::assertTrue(InboxActionKind::Dismiss->isResponse());
        self::assertFalse(InboxActionKind::Open->isResponse());
    }

    public function test_a_workflow_question_offers_its_answer_and_no_dismissal(): void
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Workflow, title: 'Child 3 has no design', blocking: true, origin: InboxAskOrigin::Loupe, options: ['Link', 'Design']);
        $this->em->persist($item);
        $this->em->flush();

        self::assertFalse($item->dismissible);
        self::assertSame([InboxActionKind::Answer], $this->kinds($item));
        self::assertSame([InboxActionKind::Answer], $this->defaults($item));
    }

    public function test_a_notice_offers_no_response(): void
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Notice, title: 'The repository is blocked', blocking: false, origin: InboxAskOrigin::Loupe);
        $this->em->persist($item);
        $this->em->flush();

        self::assertSame([], $this->kinds($item));
    }

    public function test_a_review_of_a_person_offers_its_verdict_and_a_dismissal(): void
    {
        $item = $this->reviewOf($this->document($this->em, $this->project))->item;

        self::assertSame([InboxActionKind::SubmitReview, InboxActionKind::Dismiss], $this->kinds($item));
        self::assertSame([InboxActionKind::SubmitReview], $this->defaults($item));
    }

    public function test_the_flag_decides_dismiss_but_a_review_whose_document_is_gone_always_offers_it(): void
    {
        $review = $this->reviewOf($this->document($this->em, $this->project));
        $item = $review->item;
        $item->dismissible = false;
        $this->em->flush();
        self::assertSame([InboxActionKind::SubmitReview], $this->kinds($item));

        $review->document = null;
        $this->em->flush();
        self::assertSame([InboxActionKind::SubmitReview, InboxActionKind::Dismiss], $this->kinds($item));
    }

    public function test_a_closed_item_offers_nothing_but_a_new_session(): void
    {
        $this->sessionBridge();
        $item = $this->questionSuggesting([['action' => 'start-session', 'reason' => 'Look into it.', 'recommended' => false, 'cardId' => null]]);
        self::assertSame([InboxActionKind::Answer, InboxActionKind::StartSession, InboxActionKind::Dismiss], $this->kinds($item));

        $item->state = InboxItemState::Resolved;
        $item->closedBy = InboxCloser::Owner;
        $item->selectedOptions = [0];
        $this->em->flush();

        self::assertSame([InboxActionKind::StartSession], $this->kinds($item));
        self::assertSame([], $this->defaults($item));
    }

    public function test_a_closed_item_with_no_session_to_start_offers_nothing(): void
    {
        $item = $this->questionSuggesting([['action' => 'open', 'reason' => 'Read the card first.', 'recommended' => true, 'cardId' => null]]);
        $item->state = InboxItemState::Declined;
        $item->closedBy = InboxCloser::Owner;
        $this->em->flush();

        self::assertSame([], $this->kinds($item));
    }

    public function test_a_closed_question_or_todo_offers_a_new_session_that_no_agent_suggested(): void
    {
        $this->sessionBridge();
        $question = $this->questionSuggesting([['action' => 'open', 'reason' => 'Read the card first.', 'recommended' => true, 'cardId' => null]], 100);
        $todo = $this->todo(null);
        foreach ([$question, $todo] as $item) {
            $item->state = InboxItemState::Resolved;
            $item->closedBy = InboxCloser::Owner;
        }
        $this->em->flush();

        self::assertSame([InboxActionKind::StartSession], $this->kinds($question));
        self::assertSame([InboxActionKind::StartSession], $this->kinds($todo));
    }

    public function test_a_closed_wait_item_offers_nothing_but_a_new_session(): void
    {
        $this->sessionBridge();
        $item = $this->waitItem([$this->runWait($this->workerRun(WorkerRunState::Blocked))]);
        $item->state = InboxItemState::Cancelled;
        $item->closedBy = InboxCloser::Loupe;
        $this->em->flush();

        self::assertSame([InboxActionKind::StartSession], $this->kinds($item));
    }

    /** @return list<InboxActionKind> */
    private function kinds(InboxItem $item): array
    {
        return array_map(static fn (ItemAction $action): InboxActionKind => $action->kind, $this->actions()->forItem($item));
    }

    private function reviewOf(Document $document): InboxReview
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Review, title: 'Review the design', blocking: true, origin: InboxAskOrigin::Agent);
        $review = new InboxReview($item, $document);
        $this->em->persist($item);
        $this->em->persist($review);
        $this->em->flush();

        return $review;
    }

    public function test_start_session_is_disabled_while_no_bridge_reports_the_session_capability(): void
    {
        $item = $this->waitItem([$this->runWait($this->workerRun(WorkerRunState::Blocked))]);

        self::assertSame('inbox.action.start-session.disabled', $this->startSession($item)->disabledReasonKey);
        self::assertSame('inbox.action.start-session.label', $this->startSession($item)->labelKey);
        self::assertSame('inbox.action.start-session.effect', $this->startSession($item)->effectKey);
        self::assertFalse($this->startSession($item)->default);
    }

    public function test_start_session_is_enabled_with_a_live_bridge_that_reports_the_capability(): void
    {
        $this->sessionBridge();
        $item = $this->waitItem([$this->runWait($this->workerRun(WorkerRunState::Blocked))]);

        self::assertNull($this->startSession($item)->disabledReasonKey);
    }

    public function test_a_quiet_bridge_does_not_enable_start_session(): void
    {
        $this->sessionBridge(new \DateTimeImmutable('-1 day'));
        $item = $this->waitItem([$this->runWait($this->workerRun(WorkerRunState::Blocked))]);

        self::assertSame('inbox.action.start-session.disabled', $this->startSession($item)->disabledReasonKey);
    }

    public function test_a_bridge_that_does_not_follow_the_project_or_claim_work_does_not_enable_start_session(): void
    {
        $this->sessionBridge(projects: [Uuid::v4()->toRfc4122()]);
        $this->sessionBridge(capabilities: [Bridge::CAPABILITY_SESSION]);
        $item = $this->waitItem([$this->runWait($this->workerRun(WorkerRunState::Blocked))]);

        self::assertSame('inbox.action.start-session.disabled', $this->startSession($item)->disabledReasonKey);
    }

    public function test_a_question_that_suggests_start_session_offers_it_enabled_with_a_live_bridge(): void
    {
        $this->sessionBridge();
        $item = $this->questionSuggesting([['action' => 'start-session', 'reason' => 'Look into it.', 'recommended' => false, 'cardId' => null]]);

        self::assertNull($this->startSession($item)->disabledReasonKey);
        self::assertSame($this->card->id?->toRfc4122(), $this->actions()->cardIdOf($item, InboxActionKind::Open)?->toRfc4122());
    }

    /** @return iterable<string, array{string, WorkRequestState, bool}> */
    public static function requestsOfTheCard(): iterable
    {
        yield 'an open session request' => [WorkRequest::SESSION_KIND, WorkRequestState::Open, false];
        yield 'a claimed session request' => [WorkRequest::SESSION_KIND, WorkRequestState::Claimed, false];
        yield 'a session request that ended' => [WorkRequest::SESSION_KIND, WorkRequestState::Done, true];
        yield 'a live request of another kind' => ['implement', WorkRequestState::Open, true];
    }

    /** Every item of the card follows one live session request, because the card takes only one at a time. */
    #[DataProvider('requestsOfTheCard')]
    public function test_start_session_is_hidden_on_every_item_of_the_card_while_a_session_request_is_live(string $kind, WorkRequestState $state, bool $offered): void
    {
        $this->sessionBridge();
        $wait = $this->waitItem([$this->runWait($this->workerRun(WorkerRunState::Blocked))]);
        $question = $this->questionSuggesting([['action' => 'start-session', 'reason' => 'Look into it.', 'recommended' => false, 'cardId' => null]], 98);
        $request = new WorkRequest($this->project, WorkSubject::CARD, $this->card->id ?? throw new \LogicException('Stored.'), $this->card->number, $kind, null, 'inbox:start-session', new \DateTimeImmutable());
        $request->state = $state;
        $this->em->persist($request);
        $this->em->flush();

        foreach ($this->actions()->forItems([$wait, $question]) as $actions) {
            self::assertSame($offered, array_any($actions, static fn (ItemAction $action): bool => InboxActionKind::StartSession === $action->kind));
        }
    }

    public function test_the_card_of_a_wait_item_is_the_card_of_its_watch(): void
    {
        $item = $this->waitItem([$this->runWait($this->workerRun(WorkerRunState::Blocked))]);

        self::assertSame($this->card->id?->toRfc4122(), $this->actions()->cardIdOf($item, InboxActionKind::Open)?->toRfc4122());
        self::assertNull($this->actions()->cardIdOf($this->item($this->em, $this->project, 98), InboxActionKind::Open));
    }

    private function itemSessionBridge(?string $name = null): Bridge
    {
        return $this->sessionBridge(workKinds: [Bridge::workKindOf(InboxItem::SUBJECT_TYPE, WorkRequest::SESSION_KIND)], name: $name);
    }

    private function notice(string $title = 'The repository is blocked'): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Notice, title: $title, blocking: false, origin: InboxAskOrigin::Loupe, body: 'Look at the remote.');
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function link(InboxItem $item, Card ...$cards): void
    {
        foreach ($cards as $card) {
            $item->cards->add(new InboxItemCard($item, $card));
        }
        $this->em->flush();
    }

    public function test_a_session_from_an_item_with_no_card_is_about_the_item_and_needs_the_item_work_kind(): void
    {
        $this->itemSessionBridge('geoffrey-mbp');
        $item = $this->notice();

        $subject = $this->actions()->sessionSubjectOf($item);
        $action = $this->startSession($item);

        self::assertSame(InboxItem::SUBJECT_TYPE, $subject->type);
        self::assertSame($item->id?->toRfc4122(), $subject->id->toRfc4122());
        self::assertNull($action->disabledReasonKey);
        self::assertSame('geoffrey-mbp', $action->bridgeName);
        self::assertStringContainsString('The repository is blocked', (string) $action->sessionPrompt);
    }

    public function test_a_bridge_without_the_item_work_kind_leaves_the_item_session_off(): void
    {
        $this->sessionBridge();
        $item = $this->notice();

        $action = $this->startSession($item);

        self::assertSame('inbox.action.start-session.disabled', $action->disabledReasonKey);
        self::assertNull($action->bridgeName);
    }

    public function test_an_item_with_no_card_and_no_bridge_that_opens_sessions_offers_no_session(): void
    {
        self::assertSame([], $this->kinds($this->notice()));
        $this->sessionBridge(capabilities: [Bridge::CAPABILITY_WORK_REQUESTS]);
        self::assertSame([], $this->kinds($this->notice('Another notice')));
    }

    public function test_an_item_with_one_card_starts_its_session_on_the_card_with_any_session_bridge(): void
    {
        $this->sessionBridge();
        $item = $this->notice();
        $this->link($item, $this->card);

        $subject = $this->actions()->sessionSubjectOf($item);
        $action = $this->startSession($item);

        self::assertSame(WorkSubject::CARD, $subject->type);
        self::assertSame($this->card->id?->toRfc4122(), $subject->id->toRfc4122());
        self::assertNull($action->disabledReasonKey);
        self::assertNull($action->bridgeName);
    }

    public function test_an_item_with_several_cards_starts_its_session_about_the_item(): void
    {
        $this->itemSessionBridge();
        $item = $this->notice();
        $this->link($item, $this->card, $this->card($this->em, $this->project, 8));

        self::assertSame(InboxItem::SUBJECT_TYPE, $this->actions()->sessionSubjectOf($item)->type);
        self::assertNull($this->startSession($item)->disabledReasonKey);
    }

    public function test_the_card_that_a_suggestion_names_is_the_subject_of_an_item_with_several_cards(): void
    {
        $this->itemSessionBridge();
        $second = $this->card($this->em, $this->project, 8);
        $item = $this->questionSuggesting([['action' => 'start-session', 'reason' => 'Look into it.', 'recommended' => false, 'cardId' => $second->id?->toRfc4122()]]);
        $this->link($item, $second);

        $subject = $this->actions()->sessionSubjectOf($item);

        self::assertSame(WorkSubject::CARD, $subject->type);
        self::assertSame($second->id?->toRfc4122(), $subject->id->toRfc4122());
    }

    public function test_several_bridges_leave_the_hint_without_a_name(): void
    {
        $this->itemSessionBridge('one');
        $this->itemSessionBridge('two');

        self::assertNull($this->startSession($this->notice())->bridgeName);
    }

    /** @return iterable<string, array{WorkRequestState, bool}> */
    public static function requestsOfTheItem(): iterable
    {
        yield 'an open session request' => [WorkRequestState::Open, false];
        yield 'a claimed session request' => [WorkRequestState::Claimed, false];
        yield 'a session request that ended' => [WorkRequestState::Done, true];
    }

    #[DataProvider('requestsOfTheItem')]
    public function test_a_live_session_request_of_the_item_hides_start_session(WorkRequestState $state, bool $offered): void
    {
        $this->itemSessionBridge();
        $item = $this->notice();
        $request = new WorkRequest($this->project, InboxItem::SUBJECT_TYPE, $item->id ?? throw new \LogicException('Stored.'), null, WorkRequest::SESSION_KIND, Bridge::CAPABILITY_SESSION, 'inbox:start-session', new \DateTimeImmutable());
        $request->state = $state;
        $this->em->persist($request);
        $this->em->flush();

        self::assertSame($offered, [] !== $this->kinds($item));
    }

    public function test_a_session_request_of_another_item_does_not_hide_start_session(): void
    {
        $this->itemSessionBridge();
        $other = $this->notice('Other');
        $item = $this->notice();
        $request = new WorkRequest($this->project, InboxItem::SUBJECT_TYPE, $other->id ?? throw new \LogicException('Stored.'), null, WorkRequest::SESSION_KIND, Bridge::CAPABILITY_SESSION, 'inbox:start-session', new \DateTimeImmutable());
        $this->em->persist($request);
        $this->em->flush();

        self::assertSame([InboxActionKind::StartSession], $this->kinds($item));
    }

    public function test_a_closed_item_with_no_card_offers_a_session_only_with_an_item_bridge(): void
    {
        $this->sessionBridge();
        $closed = $this->item($this->em, $this->project, 80);
        $closed->state = InboxItemState::Resolved;
        $closed->closedBy = InboxCloser::Owner;
        $this->em->flush();
        self::assertSame([], $this->kinds($closed));

        $this->itemSessionBridge();
        self::assertSame([InboxActionKind::StartSession], $this->kinds($closed));
    }

    public function test_the_agent_prompt_wins_over_the_written_prompt(): void
    {
        $this->itemSessionBridge();
        $item = $this->questionSuggesting([['action' => 'start-session', 'reason' => 'Look into it.', 'recommended' => false, 'cardId' => null, 'prompt' => 'Use the skill.']]);

        self::assertSame('Use the skill.', $this->startSession($item)->sessionPrompt);
        self::assertSame('Use the skill.', $this->actions()->sessionPromptFor($item));
    }

    public function test_an_open_question_that_suggests_no_session_has_no_session_prompt(): void
    {
        self::assertNull($this->actions()->sessionPromptFor($this->item($this->em, $this->project, 81)));
    }

    private function startSession(InboxItem $item): ItemAction
    {
        return array_find($this->actions()->forItem($item), static fn (ItemAction $action): bool => InboxActionKind::StartSession === $action->kind)
            ?? throw new \LogicException('Start a session fits an item with a card.');
    }

    /**
     * @param list<string>|null $capabilities
     * @param list<string>|null $projects
     * @param list<string>      $workKinds
     */
    private function sessionBridge(?\DateTimeImmutable $lastSeenAt = null, ?array $capabilities = null, ?array $projects = null, array $workKinds = [], ?string $name = null): Bridge
    {
        $bridge = new Bridge($this->project->owner, Uuid::v4(), $projects ?? [$this->project->id?->toRfc4122() ?? ''], 'b4e39aa7', $lastSeenAt ?? new \DateTimeImmutable());
        $bridge->capabilities = $capabilities ?? [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $bridge->workKinds = $workKinds;
        $bridge->name = $name;
        $this->em->persist($bridge);
        $this->em->flush();

        return $bridge;
    }

    public function test_a_question_with_no_link_offers_its_answer_and_a_dismissal_only(): void
    {
        $item = $this->item($this->em, $this->project);
        $this->em->flush();

        self::assertSame([InboxActionKind::Answer, InboxActionKind::Dismiss], $this->kinds($item));
    }

    public function test_for_items_keys_each_item_by_its_id(): void
    {
        $run = $this->waitItem([$this->runWait($this->workerRun(WorkerRunState::Blocked))]);
        $question = $this->item($this->em, $this->project, 99);
        $this->em->flush();

        $runKey = ($run->id ?? throw new \LogicException('The item is stored.'))->toRfc4122();
        $questionKey = ($question->id ?? throw new \LogicException('The item is stored.'))->toRfc4122();
        $actions = $this->actions()->forItems([$run, $question]);

        self::assertSame([$runKey, $questionKey], array_keys($actions));
        self::assertSame(InboxActionKind::ResumeRun, $actions[$runKey][0]->kind);
        self::assertSame([InboxActionKind::Answer, InboxActionKind::Dismiss], array_map(static fn (ItemAction $action): InboxActionKind => $action->kind, $actions[$questionKey]));
        self::assertSame([], $this->actions()->forItems([]));
    }

    public function test_a_plain_to_do_offers_complete_by_default_and_dismiss_when_the_flag_allows(): void
    {
        $item = $this->todo(null);

        self::assertSame([[InboxActionKind::Complete, true], [InboxActionKind::Dismiss, false]], $this->summary($item));

        $item->dismissible = false;

        self::assertSame([[InboxActionKind::Complete, true]], $this->summary($item));
    }

    public function test_a_to_do_with_a_suggestion_is_not_plain(): void
    {
        $item = $this->todo([['action' => 'open', 'reason' => 'Read it.', 'recommended' => true, 'cardId' => null]]);

        self::assertFalse(ItemActions::isPlain($item));
        self::assertTrue(ItemActions::isPlain($this->todo(null)));
        self::assertContains([InboxActionKind::Open, true], $this->summary($item));
    }

    public function test_a_recommended_suggestion_that_fits_is_the_default_and_carries_its_reason(): void
    {
        $run = $this->workerRun(WorkerRunState::Blocked, ['suggestedActions' => [
            ['action' => 'open', 'reason' => 'Read the card first.', 'recommended' => true],
            ['action' => 'resume-run', 'reason' => 'The key is in the vault now.'],
        ]]);
        $actions = $this->actions()->forItem($this->waitItem([$this->runWait($run)]));

        self::assertSame(
            [[InboxActionKind::ResumeRun, false, 'The key is in the vault now.'], [InboxActionKind::Open, true, 'Read the card first.'], [InboxActionKind::StartSession, false, null], [InboxActionKind::Dismiss, false, null]],
            array_map(static fn (ItemAction $action): array => [$action->kind, $action->default, $action->reason], $actions),
        );
    }

    public function test_a_recommended_suggestion_that_does_not_fit_leaves_the_default_of_the_wait(): void
    {
        $run = $this->workerRun(WorkerRunState::Blocked, ['suggestedActions' => [
            ['action' => 'retry-card', 'reason' => 'Retry it.', 'recommended' => true],
        ]]);

        self::assertSame([InboxActionKind::ResumeRun], $this->defaults($this->waitItem([$this->runWait($run)])));
    }

    public function test_a_recommended_dismiss_never_becomes_the_default(): void
    {
        $run = $this->workerRun(WorkerRunState::Blocked, ['suggestedActions' => [
            ['action' => 'dismiss', 'reason' => 'Nothing to do.', 'recommended' => true],
        ]]);

        self::assertSame([InboxActionKind::ResumeRun], $this->defaults($this->waitItem([$this->runWait($run)])));
    }

    public function test_a_newer_review_wait_overrides_the_suggestions_of_an_older_run_wait(): void
    {
        $run = $this->workerRun(WorkerRunState::Blocked, ['suggestedActions' => [
            ['action' => 'open', 'reason' => 'Read the card first.', 'recommended' => true],
            ['action' => 'resume-run', 'reason' => 'The key is in the vault now.'],
        ]]);
        $actions = $this->actions()->forItem($this->waitItem([
            $this->runWait($run, new \DateTimeImmutable('2026-10-13 10:00:00')),
            $this->documentWait(new \DateTimeImmutable('2026-10-13 10:00:05')),
        ]));

        self::assertSame(
            [[InboxActionKind::ResumeRun, false, null], [InboxActionKind::Review, true, null], [InboxActionKind::Open, false, null], [InboxActionKind::StartSession, false, null], [InboxActionKind::Dismiss, false, null]],
            array_map(static fn (ItemAction $action): array => [$action->kind, $action->default, $action->reason], $actions),
        );
    }

    public function test_a_todo_offers_only_the_suggestions_that_fit_and_defaults_to_the_recommended_one(): void
    {
        $todo = $this->todo([
            ['action' => 'open', 'reason' => 'Read the card first.', 'recommended' => true, 'cardId' => null],
            ['action' => 'retry-card', 'reason' => 'Retry it.', 'recommended' => false, 'cardId' => null],
            ['action' => 'dismiss', 'reason' => 'Nothing to do.', 'recommended' => false, 'cardId' => null],
        ]);

        self::assertSame(
            [[InboxActionKind::Complete, false, null], [InboxActionKind::Open, true, 'Read the card first.'], [InboxActionKind::Dismiss, false, 'Nothing to do.']],
            array_map(static fn (ItemAction $action): array => [$action->kind, $action->default, $action->reason], $this->actions()->forItem($todo)),
        );
    }

    public function test_a_todo_with_no_suggestions_offers_its_completion_and_a_dismissal(): void
    {
        self::assertSame([InboxActionKind::Complete, InboxActionKind::Dismiss], $this->kinds($this->todo(null)));
    }

    public function test_a_todo_without_a_recommendation_defaults_to_its_completion(): void
    {
        $todo = $this->todo([['action' => 'open', 'reason' => 'Read the card first.', 'recommended' => false, 'cardId' => null]]);

        self::assertSame([InboxActionKind::Complete], $this->defaults($todo));
    }

    /** @param list<array{action: string, reason: string, recommended: bool, cardId: ?string}> $suggestions */
    private function questionSuggesting(array $suggestions, int $number = 1): InboxItem
    {
        $item = $this->item($this->em, $this->project, $number);
        $item->suggestedActions = $suggestions;
        $item->cards->add(new InboxItemCard($item, $this->card));
        $this->em->flush();

        return $item;
    }

    /** @param list<array{action: string, reason: string, recommended: bool, cardId: ?string}>|null $suggestions */
    private function todo(?array $suggestions): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Export the design system', blocking: false, origin: InboxAskOrigin::Agent);
        $item->suggestedActions = $suggestions;
        $item->cards->add(new InboxItemCard($item, $this->card));
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    /** @param list<InboxCardWait> $waits */
    private function waitItem(array $waits): InboxItem
    {
        $watch = $this->watchOf();
        foreach ($waits as $wait) {
            self::assertSame($watch, $wait->watch);
            $watch->waits->add($wait);
        }
        $this->em->flush();

        return $watch->item;
    }

    /** The one watch of the card, made on first use, because a wait names its watch. */
    private function watchOf(): InboxCardWatch
    {
        if (null === $this->watch) {
            $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Wait, title: '#7 Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
            $this->em->persist($item);
            $this->watch = new InboxCardWatch($item, $this->card->id ?? throw new \LogicException('The card is stored.'), $this->card->number);
            $this->em->persist($this->watch);
        }

        return $this->watch;
    }

    /** @param array<string, mixed>|null $resultFields */
    private function workerRun(WorkerRunState $state, ?array $resultFields = null): WorkerRun
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: $this->bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('The card is stored.'),
            cardNumber: $this->card->number,
            workKind: 'implement',
            state: $state,
            runKey: Uuid::v7(),
            sessionId: Uuid::v4(),
        );
        $run->resultFields = $resultFields;
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function runWait(WorkerRun $run, \DateTimeImmutable $startedAt = new \DateTimeImmutable('2026-10-13 10:00:00')): InboxCardWait
    {
        return new InboxCardWait($this->watchOf(), 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: $run->id, startedAt: $startedAt);
    }

    private function documentWait(\DateTimeImmutable $startedAt = new \DateTimeImmutable('2026-10-13 10:00:00')): InboxCardWait
    {
        $document = $this->document($this->em, $this->project);
        $this->em->flush();

        return new InboxCardWait($this->watchOf(), 'document-in-review', InboxCardWaitType::Document, 'marker.document_in_review', documentId: $document->id, versionNumber: 1, startedAt: $startedAt);
    }

    private function pullRequestWait(): InboxCardWait
    {
        return new InboxCardWait($this->watchOf(), 'waits-for-approval', InboxCardWaitType::PullRequest, 'marker.waits_for_approval', pullRequestId: Uuid::v4(), headSha: 'abc123');
    }

    private function pauseWait(PauseKind $kind, \DateTimeImmutable $startedAt = new \DateTimeImmutable('2026-10-13 10:00:00')): InboxCardWait
    {
        $handler = self::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $handler);
        $card = $this->em->find(Card::class, $this->card->id) ?? throw new \LogicException('The card is stored.');
        $pause = $handler(new PauseCardCommand($card, 'on-hold', 'review-rule', $kind));
        self::assertNotNull($pause);

        return new InboxCardWait($this->watchOf(), 'paused', InboxCardWaitType::CardPause, 'marker.paused', startedAt: $startedAt, pauseId: $pause->id);
    }

    /**
     * The menu without Start a session, which the tests of its own cover.
     *
     * @return list<array{InboxActionKind, bool}>
     */
    private function summary(InboxItem $item): array
    {
        return array_values(array_map(
            static fn (ItemAction $action): array => [$action->kind, $action->default],
            array_filter($this->actions()->forItem($item), static fn (ItemAction $action): bool => InboxActionKind::StartSession !== $action->kind),
        ));
    }

    /** @return list<InboxActionKind> */
    private function defaults(InboxItem $item): array
    {
        return array_values(array_map(
            static fn (ItemAction $action): InboxActionKind => $action->kind,
            array_filter($this->actions()->forItem($item), static fn (ItemAction $action): bool => $action->default),
        ));
    }

    /** Built by hand, because nothing injects the service yet and the compiler drops an unused private service. */
    private function actions(): ItemActions
    {
        $container = self::getContainer();
        $watches = $container->get(InboxCardWatchRepository::class);
        $runs = $container->get(WorkerRunRepository::class);
        $controls = $container->get(WorkerRunControls::class);
        $pauses = $container->get(CardPauses::class);
        $stageWorks = $container->get(TemplateStageWorks::class);
        $bridges = $container->get(BridgeRepository::class);
        $liveness = $container->get(BridgeLiveness::class);
        self::assertInstanceOf(BridgeRepository::class, $bridges);
        self::assertInstanceOf(BridgeLiveness::class, $liveness);
        $sessionBridges = new SessionBridges($bridges, $liveness);
        $workRequests = $container->get(WorkRequestRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $watches);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);
        self::assertInstanceOf(WorkerRunControls::class, $controls);
        self::assertInstanceOf(CardPauses::class, $pauses);
        self::assertInstanceOf(StageWorks::class, $stageWorks);
        self::assertInstanceOf(WorkRequestRepository::class, $workRequests);

        $inboxActions = $container->get(InboxActionRepository::class);
        $inboxAsks = $container->get(InboxAskRepository::class);
        self::assertInstanceOf(InboxActionRepository::class, $inboxActions);
        self::assertInstanceOf(InboxAskRepository::class, $inboxAsks);

        $chunks = self::getContainer()->get(WorkerRunOutputChunkRepository::class);
        self::assertInstanceOf(WorkerRunOutputChunkRepository::class, $chunks);

        $waitText = self::getContainer()->get(CardWaitText::class);
        self::assertInstanceOf(CardWaitText::class, $waitText);

        $holds = self::getContainer()->get(CardHoldRepository::class);
        self::assertInstanceOf(CardHoldRepository::class, $holds);

        $reviewLookup = self::getContainer()->get(InboxReviewLookup::class);
        self::assertInstanceOf(InboxReviewLookup::class, $reviewLookup);

        $sessionPrompts = self::getContainer()->get(SessionPromptWriter::class);
        self::assertInstanceOf(SessionPromptWriter::class, $sessionPrompts);

        return new ItemActions(
            $watches,
            $runs,
            $controls,
            $pauses,
            $sessionBridges,
            $workRequests,
            $stageWorks,
            new AskingBridge($bridges, $inboxAsks, $runs),
            $this->resolver($liveness),
            new RunCommandRuns($inboxActions, $workRequests, $runs, $chunks),
            $waitText,
            $holds,
            $reviewLookup,
            $sessionPrompts,
        );
    }

    private function resolver(BridgeLiveness $liveness): RunCommandResolver
    {
        $cards = self::getContainer()->get(CardRepository::class);
        $contexts = self::getContainer()->get(WorkRequestContexts::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        self::assertInstanceOf(WorkRequestContexts::class, $contexts);

        return new RunCommandResolver($liveness, $cards, $contexts);
    }
}
