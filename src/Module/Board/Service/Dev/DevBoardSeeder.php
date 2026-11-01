<?php

declare(strict_types=1);

namespace App\Module\Board\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardSource;
use App\Module\Board\Entity\CardSourceKind;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\PullRequestUrlResolver;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Service\Dev\DevRunCardLinks;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;
use App\Module\Workflow\Contract\PauseKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the showcase cards of the dev project: pull requests in every
 * state, a card with history, an epic lane, a paused card and a card with an
 * outdated approval. Each record has a fixed id and is restored on its own.
 */
#[When('dev')]
#[When('test')]
final readonly class DevBoardSeeder implements DevSeederInterface
{
    public const string KEY = 'board';

    public const string WAITING_CARD_TITLE = 'Board onboarding';
    public const string OUTDATED_APPROVAL_TITLE = 'Retry a declined payment';

    /** The cards of the showcase, newest first, with the seconds before now at which each was created. */
    private const array AGE = [
        'checkout' => 600_000,
        'history' => 610_000,
        'column-rules' => 590_000,
        'onboarding' => 580_000,
        'project-export' => 570_000,
        'export-documents' => 560_000,
        'sync-forge-state' => 550_000,
        'outdated-approval' => 540_000,
        'empty-basket' => 530_000,
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private PullRequestUrlResolver $pullRequests,
        private ForgePullRequestRepository $forgePullRequests,
        private DevRunCardLinks $runCardLinks,
    ) {
    }

    public static function cardId(string $slug): Uuid
    {
        return DevSeedId::of('board/card/'.$slug);
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::devProjectId())
            ?? throw new \LogicException('The dev project does not exist.');
        $owner = $context->devUser();

        $columns = [];
        foreach ($this->boardColumns->findForProject($project) as $column) {
            $columns[$column->slug] = $column;
        }
        $backlog = $columns['backlog'] ?? throw new \LogicException('The project has no backlog column.');
        $inProgress = $columns['in-progress'] ?? $backlog;

        [$checkout, $created] = $this->card($context, $project, 'checkout', fn (int $number, \DateTimeImmutable $at): Card => new Card(
            project: $project,
            column: $inProgress,
            title: 'A simpler checkout',
            body: 'Replace the legacy checkout with a single-page flow. Keep saved subscriptions and recovery behavior intact.',
            number: $number,
            type: 'feature',
            createdAt: $at,
            source: new CardSource(CardSourceKind::Person),
        ));
        if ($created) {
            $this->linkPullRequest($context, $checkout, 438, PullRequestChecks::Failed, PullRequestMergeability::Mergeable, PullRequestReview::Required, ['phpunit', 'e2e-chromium']);
        }

        [$history, $created] = $this->card($context, $project, 'history', fn (int $number, \DateTimeImmutable $at): Card => new Card(
            project: $project,
            column: $inProgress,
            title: 'Worker run history',
            body: 'Give every reported run a place in the project: its outcome, its duration, the rule that started it and the card it belonged to.',
            number: $number,
            type: 'feature',
            createdAt: $at,
        ));
        if ($created) {
            $this->linkPullRequest($context, $history, 441, PullRequestChecks::Passed, PullRequestMergeability::Conflicting, PullRequestReview::Required);
            $this->linkPullRequest($context, $history, 449, PullRequestChecks::Passed, PullRequestMergeability::Blocked, PullRequestReview::Required);
            $this->seedHistoryEvents($context, $project, $history, $owner, $columns);
        }
        $this->seedHistoryRuns($context, $project, $history);

        [$columnRules, $created] = $this->card($context, $project, 'column-rules', fn (int $number, \DateTimeImmutable $at): Card => new Card(
            project: $project,
            column: $columns['next'] ?? $backlog,
            title: 'Safer column changes',
            body: 'A column rename must not silently detach the rules that point at it. Decide the handoff behavior before implementation.',
            number: $number,
            type: 'bug',
            createdAt: $at,
            source: CardSource::run(DevSeedId::of('board/source/column-rules/project'), DevSeedId::of('board/source/column-rules/run')),
        ));
        if ($created) {
            $this->linkPullRequest($context, $columnRules, 445, PullRequestChecks::Failed, PullRequestMergeability::Mergeable, PullRequestReview::ChangesRequested, ['lint']);
        }

        [$onboarding, $created] = $this->card($context, $project, 'onboarding', fn (int $number, \DateTimeImmutable $at): Card => new Card(
            project: $project,
            column: $columns['tech-design'] ?? $this->techDesignColumn($project, $columns),
            title: self::WAITING_CARD_TITLE,
            body: 'Make the first agent handoff obvious. Explain which rule runs when a card enters Ready and what the person should expect back.',
            number: $number,
            type: 'feature',
            createdAt: $at,
            source: new CardSource(CardSourceKind::Loupe),
        ));
        if ($created) {
            $this->linkPullRequest($context, $onboarding, 447, PullRequestChecks::Passed, PullRequestMergeability::Mergeable, PullRequestReview::Approved);
        }

        $this->seedEpicLane($context, $project, $columns['next'] ?? $backlog, $inProgress);
        $this->seedPausedCard($context, $project, $inProgress);
        $this->seedOutdatedApproval($context, $project, $columns['in-review'] ?? $columns['in-progress'] ?? $backlog);
        $this->seedBasketCard($context, $project);

        $this->em->flush();
        foreach ([$history, $onboarding, $this->cards->find(self::cardId('export-documents')), $this->cards->find(self::cardId('sync-forge-state'))] as $card) {
            if ($card instanceof Card) {
                $this->runCardLinks->relink($project, $card->id ?? throw new \LogicException('A stored card has an id.'), $card->number);
            }
        }
    }

    /**
     * Finds the card by its fixed id, or builds it with the next number and the age of its slug.
     *
     * @param \Closure(int, \DateTimeImmutable): Card $build
     *
     * @return array{Card, bool} the card, and whether this call created it
     */
    private function card(SeedContext $context, Project $project, string $slug, \Closure $build): array
    {
        $existing = $this->cards->find(self::cardId($slug));
        if ($existing instanceof Card) {
            return [$existing, false];
        }

        $card = $build($this->cards->nextNumber($project), $context->at(self::AGE[$slug]));
        $context->ids->assign($card, self::cardId($slug));
        $this->em->persist($card);
        $this->em->flush();

        return [$card, true];
    }

    /**
     * An open column after Next, so a tech design in review on a card there waits.
     *
     * @param array<string, BoardColumn> $columns
     */
    private function techDesignColumn(Project $project, array $columns): BoardColumn
    {
        $position = isset($columns['next']) ? $columns['next']->position + 1 : \count($columns);
        foreach ($columns as $column) {
            if ($column->position >= $position) {
                ++$column->position;
            }
        }
        $column = new BoardColumn(project: $project, label: 'Tech design', slug: 'tech-design', position: $position);
        $this->em->persist($column);

        return $column;
    }

    /**
     * No App installation reads example/atlas, so a refresh fails as not
     * transient and keeps this state.
     *
     * @param list<string> $failedChecks
     */
    private function linkPullRequest(SeedContext $context, Card $card, int $number, PullRequestChecks $checks, PullRequestMergeability $mergeability, PullRequestReview $review, array $failedChecks = []): ForgePullRequest
    {
        [, $state] = $this->link($card, $number);
        $state->headSha = hash('sha1', 'atlas-'.$number);
        $state->baseBranch = 'main';
        $state->checks = $checks;
        $state->checksSha = $state->headSha;
        $state->failedChecks = $failedChecks;
        $state->mergeability = $mergeability;
        $state->review = $review;
        $state->changesRequestedAt = PullRequestReview::ChangesRequested === $review ? $context->at(1_800) : null;
        $state->refreshedAt = $context->at(300);

        return $state;
    }

    /** @return array{CardPullRequest, ForgePullRequest} */
    private function link(Card $card, int $number): array
    {
        $links = $this->pullRequests->linksFor($card, ['https://github.com/example/atlas/pull/'.$number]);
        $link = $links[0] ?? throw new \LogicException('The pull request resolver returned no link.');
        $this->em->persist($link);
        $card->pullRequests->add($link);

        $state = $this->forgePullRequests->findOneBy(['project' => $card->project, 'forge' => 'github', 'repository' => 'example/atlas', 'number' => $number])
            ?? new ForgePullRequest($card->project, 'github', 'example/atlas', $number);
        $this->em->persist($state);

        return [$link, $state];
    }

    /**
     * A History tab with one row of each kind.
     *
     * @param array<string, BoardColumn> $columns
     */
    private function seedHistoryEvents(SeedContext $context, Project $project, Card $card, User $owner, array $columns): void
    {
        $details = [];
        foreach ($columns as $slug => $column) {
            $details[$slug] = CardEvent::columnDetail($column);
        }
        $backlog = $details['backlog'] ?? throw new \LogicException('The project has no backlog column.');
        $inProgress = $details['in-progress'] ?? $backlog;
        $done = $details['done'] ?? $backlog;

        $this->cardEvents->record($card, CardEventKind::Created, Actor::Human, $owner, ['column' => $backlog, 'type' => $card->type], $context->at(4 * 86_400));
        $this->cardEvents->record($card, CardEventKind::Moved, Actor::Agent, $owner, ['from' => $backlog, 'to' => $inProgress, 'cause' => null], $context->at(3 * 86_400));
        $this->cardEvents->record($card, CardEventKind::FixRequested, Actor::System, null, ['reason' => 'checks-failed', 'pullRequest' => 441], $context->at(48 * 3_600));
        $this->cardEvents->record($card, CardEventKind::RunFinished, Actor::Agent, $owner, [
            'runId' => (string) DevSeedId::of('bridge/run/history-fix'),
            'workKind' => 'fix',
            'state' => WorkerRunState::Succeeded->value,
            'interactive' => false,
            'startedAt' => $context->at(48 * 3_600 - 434)->format(\DateTimeInterface::ATOM),
            'endedAt' => $context->at(47 * 3_600)->format(\DateTimeInterface::ATOM),
            'durationSeconds' => 434,
        ], $context->at(47 * 3_600));
        $this->cardEvents->record($card, CardEventKind::ReadyToMerge, Actor::System, null, ['pullRequest' => 441], $context->at(30 * 3_600));
        $this->cardEvents->record($card, CardEventKind::Moved, Actor::System, null, ['from' => $inProgress, 'to' => $done, 'cause' => CardEventCause::workflowRule('merged', 'merged')->detail()], $context->at(28 * 3_600));
        $this->cardEvents->record($card, CardEventKind::Moved, Actor::Human, $owner, ['from' => $done, 'to' => $inProgress, 'cause' => null], $context->at(6 * 3_600));
    }

    /** A finished fix run with its request, and a run still open on the same card. */
    private function seedHistoryRuns(SeedContext $context, Project $project, Card $card): void
    {
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');

        $endedAt = $context->at(47 * 3_600);
        $this->run($context, 'history-fix', new WorkerRun(
            project: $project,
            bridgeId: DevSeedId::of('bridge/bridge/history'),
            subjectType: WorkSubject::CARD,
            subjectId: $cardId,
            cardNumber: $card->number,
            workKind: 'fix',
            state: WorkerRunState::Succeeded,
            runKey: DevSeedId::of('bridge/run-key/history-fix'),
            startedAt: $endedAt->modify('-434 seconds'),
            endedAt: $endedAt,
            exitCode: 0,
            hasResult: true,
            output: 'The failing test now passes. The run pushed one commit.',
            receivedAt: $endedAt,
            role: 'fix',
        ));

        $startedAt = $context->at(240);
        $this->run($context, 'history-open', new WorkerRun(
            project: $project,
            bridgeId: DevSeedId::of('bridge/bridge/history'),
            subjectType: WorkSubject::CARD,
            subjectId: $cardId,
            cardNumber: $card->number,
            workKind: 'implement',
            state: WorkerRunState::Running,
            runKey: DevSeedId::of('bridge/run-key/history-open'),
            startedAt: $startedAt,
            receivedAt: $startedAt,
        ));

        $requestId = DevSeedId::of('bridge/work-request/history-fix');
        if (null === $this->em->find(WorkRequest::class, $requestId)) {
            $fix = new WorkRequest(
                project: $project,
                subjectType: WorkSubject::CARD,
                subjectId: $cardId,
                cardNumber: $card->number,
                kind: 'fix',
                capability: null,
                ruleId: 'fix-on-failure',
                createdAt: $context->at(48 * 3_600),
            );
            $fix->role = 'fix';
            $fix->context = new WorkRequestContext(pullRequestNumber: 441, reason: 'checks-failed');
            $fix->state = WorkRequestState::Done;
            $fix->settledAt = $endedAt;
            $context->ids->assign($fix, $requestId);
            $this->em->persist($fix);
            $this->em->flush();
        }
    }

    /** An epic lane whose child card holds the warning of a run that gave up. */
    private function seedEpicLane(SeedContext $context, Project $project, BoardColumn $next, BoardColumn $inProgress): void
    {
        [$epic] = $this->card($context, $project, 'project-export', fn (int $number, \DateTimeImmutable $at): Card => new Card(
            project: $project,
            column: $next,
            title: 'Project export',
            body: 'Let an owner download every card, document and request of a project as one archive.',
            number: $number,
            type: 'epic',
            createdAt: $at,
        ));
        [$child, $created] = $this->card($context, $project, 'export-documents', fn (int $number, \DateTimeImmutable $at): Card => new Card(
            project: $project,
            column: $inProgress,
            title: 'Export the documents',
            body: 'Write each document version as Markdown, in a folder per document.',
            number: $number,
            type: 'feature',
            createdAt: $at,
        ));
        if ($created) {
            $child->parent = $epic;
            $this->em->flush();
        }

        $endedAt = $context->at(900);
        $this->run($context, 'epic-gave-up', new WorkerRun(
            project: $project,
            bridgeId: DevSeedId::of('bridge/bridge/epic-lane'),
            subjectType: WorkSubject::CARD,
            subjectId: $child->id ?? throw new \LogicException('A stored card has an id.'),
            cardNumber: $child->number,
            workKind: 'implement',
            state: WorkerRunState::GaveUp,
            runKey: DevSeedId::of('bridge/run-key/epic-gave-up'),
            endedAt: $endedAt,
            exitCode: 1,
            hasResult: true,
            output: 'The export tests still fail after three attempts. The archive writer cannot read a document that has no version.',
            receivedAt: $endedAt,
        ));
    }

    /** A blocked review run and the pause it caused, which the wait item shows as one line. */
    private function seedPausedCard(SeedContext $context, Project $project, BoardColumn $column): void
    {
        [$card, $created] = $this->card($context, $project, 'sync-forge-state', fn (int $number, \DateTimeImmutable $at): Card => new Card(
            project: $project,
            column: $column,
            title: 'Sync the forge state',
            body: 'Read the checks of a linked pull request again after each push.',
            number: $number,
            type: 'feature',
            createdAt: $at,
        ));

        $endedAt = $context->at(600);
        $this->run($context, 'blocked-review', new WorkerRun(
            project: $project,
            bridgeId: DevSeedId::of('bridge/bridge/paused-card'),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A stored card has an id.'),
            cardNumber: $card->number,
            workKind: 'review',
            state: WorkerRunState::Blocked,
            runKey: DevSeedId::of('bridge/run-key/blocked-review'),
            endedAt: $endedAt,
            exitCode: 0,
            hasResult: true,
            output: 'STAGE RESULT: blocked: cannot reach github.com: DNS lookup failed [reason: needs-person]. Restore the network of the worker, then resume the card.',
            receivedAt: $endedAt,
            role: 'code-review',
        ), static function (WorkerRun $run): void {
            $run->resultFields = ['title' => 'Restore the network of the review worker'];
        });
        if ($created) {
            $this->em->persist(new CardPause($card, $project, 'work-stopped', 'review', PauseKind::WorkStopped, $endedAt));
            $this->em->flush();
        }
    }

    /** A pull request that passes every check, with an approval of a head before the last push. */
    private function seedOutdatedApproval(SeedContext $context, Project $project, BoardColumn $column): void
    {
        [$card, $created] = $this->card($context, $project, 'outdated-approval', fn (int $number, \DateTimeImmutable $at): Card => new Card(
            project: $project,
            column: $column,
            title: self::OUTDATED_APPROVAL_TITLE,
            body: '',
            number: $number,
            type: 'feature',
            createdAt: $at,
        ));
        if (!$created) {
            return;
        }

        $state = $this->linkPullRequest($context, $card, 457, PullRequestChecks::Passed, PullRequestMergeability::Mergeable, PullRequestReview::Approved);
        $state->defaultBranch = 'main';
        $state->approvalId = 'atlas-review-457';
        $state->approvedAt = $context->at(3 * 3_600);
        $state->approvalSha = $state->coveredSha = hash('sha1', 'atlas-457-old');
        $state->uncoveredSha = $state->headSha;
        $state->readyToMerge = false;
        $this->em->flush();
    }

    /** The finished site-review card that the resolved comment sits on. */
    private function seedBasketCard(SeedContext $context, Project $project): void
    {
        [$basket, $created] = $this->card($context, $project, 'empty-basket', fn (int $number, \DateTimeImmutable $at): Card => new Card(
            project: $project,
            column: array_find(
                $this->boardColumns->findForProject($project),
                static fn (BoardColumn $column): bool => $column->terminal,
            ) ?? throw new \LogicException('The project has no terminal column.'),
            title: 'The empty basket reads as an error',
            body: '',
            number: $number,
            type: 'feature',
            origin: Actor::Reviewer,
            createdAt: $at,
        ));
        if ($created) {
            $basket->completedAt = $context->at(86_400);
            $this->em->flush();
        }
    }

    /** Finds the run by its fixed id, or stores the one given. */
    private function run(SeedContext $context, string $slug, WorkerRun $run, ?\Closure $adjust = null): WorkerRun
    {
        $id = DevSeedId::of('bridge/run/'.$slug);
        $existing = $this->em->find(WorkerRun::class, $id);
        if ($existing instanceof WorkerRun) {
            return $existing;
        }

        if (null !== $adjust) {
            $adjust($run);
        }
        $context->ids->assign($run, $id);
        $this->em->persist($run);
        $this->em->persist(new WorkerRunStateChange($run, $run->state, $run->endedAt ?? $run->startedAt ?? $context->now, $run->endedAt ?? $run->startedAt ?? $context->now));
        $this->em->flush();

        return $run;
    }
}
