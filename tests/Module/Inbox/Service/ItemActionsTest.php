<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\View\WorkerRunControls;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitReason;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\ItemActions;
use App\Module\Inbox\View\ItemAction;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardPauses;
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
        $stale = new InboxCardWait($this->watchOf(), InboxCardWaitTrigger::CardPaused, InboxCardWaitType::CardPause, InboxCardWaitReason::PauseRetries, pauseId: Uuid::v4());

        self::assertSame([[InboxActionKind::Open, true], [InboxActionKind::Dismiss, false]], $this->summary($this->waitItem([$stale])));
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

        $kinds = array_map(static fn (array $row): InboxActionKind => $row[0], $this->summary($item));

        self::assertSame([InboxActionKind::ResumeRun, InboxActionKind::RetryCard, InboxActionKind::Review, InboxActionKind::Open, InboxActionKind::Dismiss], $kinds);
        self::assertSame([InboxActionKind::RetryCard], $this->defaults($item));
    }

    public function test_a_question_that_links_a_card_offers_open_and_no_default(): void
    {
        $item = $this->item($this->em, $this->project);
        $item->cards->add(new InboxItemCard($item, $this->card));
        $this->em->flush();

        self::assertSame([[InboxActionKind::Open, false]], $this->summary($item));
    }

    public function test_a_question_with_no_link_offers_nothing(): void
    {
        $item = $this->item($this->em, $this->project);
        $this->em->flush();

        self::assertSame([], $this->actions()->forItem($item));
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
        self::assertSame([], $actions[$questionKey]);
        self::assertSame([], $this->actions()->forItems([]));
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
            $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Wait, title: '#7 Ship it', blocking: true);
            $this->em->persist($item);
            $this->watch = new InboxCardWatch($item, $this->card->id ?? throw new \LogicException('The card is stored.'), $this->card->number);
            $this->em->persist($this->watch);
        }

        return $this->watch;
    }

    private function workerRun(WorkerRunState $state): WorkerRun
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
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function runWait(WorkerRun $run, \DateTimeImmutable $startedAt = new \DateTimeImmutable('2026-10-13 10:00:00')): InboxCardWait
    {
        return new InboxCardWait($this->watchOf(), InboxCardWaitTrigger::RunBlocked, InboxCardWaitType::WorkerRun, InboxCardWaitReason::Blocked, runId: $run->id, startedAt: $startedAt);
    }

    private function documentWait(\DateTimeImmutable $startedAt = new \DateTimeImmutable('2026-10-13 10:00:00')): InboxCardWait
    {
        $document = $this->document($this->em, $this->project);
        $this->em->flush();

        return new InboxCardWait($this->watchOf(), InboxCardWaitTrigger::DocumentInReview, InboxCardWaitType::Document, InboxCardWaitReason::WaitingForReview, documentId: $document->id, versionNumber: 1, startedAt: $startedAt);
    }

    private function pullRequestWait(): InboxCardWait
    {
        return new InboxCardWait($this->watchOf(), InboxCardWaitTrigger::PullRequestReady, InboxCardWaitType::PullRequest, InboxCardWaitReason::WaitingForReview, pullRequestId: Uuid::v4(), headSha: 'abc123');
    }

    private function pauseWait(PauseKind $kind, \DateTimeImmutable $startedAt = new \DateTimeImmutable('2026-10-13 10:00:00')): InboxCardWait
    {
        $handler = self::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $handler);
        $card = $this->em->find(Card::class, $this->card->id) ?? throw new \LogicException('The card is stored.');
        $pause = $handler(new PauseCardCommand($card, 'on-hold', 'review-rule', $kind));
        self::assertNotNull($pause);

        return new InboxCardWait($this->watchOf(), InboxCardWaitTrigger::CardPaused, InboxCardWaitType::CardPause, InboxCardWaitReason::forPause($kind), startedAt: $startedAt, pauseId: $pause->id);
    }

    /** @return list<array{InboxActionKind, bool}> */
    private function summary(InboxItem $item): array
    {
        return array_map(static fn (ItemAction $action): array => [$action->kind, $action->default], $this->actions()->forItem($item));
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
        $workRequests = $container->get(WorkRequestRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $watches);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);
        self::assertInstanceOf(WorkerRunControls::class, $controls);
        self::assertInstanceOf(CardPauses::class, $pauses);
        self::assertInstanceOf(StageWorks::class, $stageWorks);
        self::assertInstanceOf(WorkRequestRepository::class, $workRequests);

        return new ItemActions($watches, $runs, $controls, $pauses, $stageWorks, $workRequests);
    }
}
