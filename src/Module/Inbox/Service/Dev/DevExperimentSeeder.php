<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\ExperimentDefinitionRepository;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\Dev\DevRunCardLinks;
use App\Module\Bridge\Service\Dev\DevRunUsage;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;
use App\Utils\Slug;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes experiments that compare two models on the implement work, with
 * cards, pins and costed runs, so the Comparison and Cards tabs show figures.
 *
 * It lives in Inbox, which may name a card and a worker run. The root
 * namespace may not name a card, as `phparkitect.php` says.
 */
#[When('dev')]
#[When('test')]
final readonly class DevExperimentSeeder implements DevSeederInterface
{
    public const string KEY = 'experiments';

    public const string EXPERIMENT = 'implement';

    /**
     * Name, headline and metrics of each experiment. The goal panel has three states and two problems.
     * The Simple template declares no fix-rounds, so a declared fix-rounds guard is unknown and shows a note.
     * The metric lists add the pull request history that hours to merge reads.
     *
     * @var list<array{string, array<string, mixed>|null, list<string>|null}>
     */
    private const array EXPERIMENTS = [
        [self::EXPERIMENT, null, null],
        ['implement-merge-rate', ['goal' => ['metric' => 'merge-rate', 'better' => 'higher']], null],
        ['implement-merge-time', ['goal' => ['metric' => 'hours-to-merge', 'better' => 'lower']], null],
        ['implement-goal-typo', ['goal' => ['metric' => 'hours-to-mrege', 'better' => 'lower']], null],
        ['implement-no-direction', ['goal' => ['metric' => 'hours-to-merge']], null],
        ['implement-unknown-guard', ['guards' => ['merge-rate', 'fix-rounds']], ['cost', 'merge-rate', 'duration', 'input-tokens']],
        ['implement-simple-default', null, ['cost', 'merge-rate', 'duration', 'input-tokens']],
        ['implement-template-metrics', ['goal' => ['metric' => 'hours-to-merge', 'better' => 'lower']], ['cost', 'merge-rate', 'hours-to-merge']],
    ];

    private const array MODELS = ['opus' => 'claude-opus-5-5', 'sonnet' => 'claude-sonnet-5-5'];

    /** The last card has a pin and no run, so the figures leave it out. */
    private const array CARDS = [
        ['Export the board as CSV', 'opus', 'feature', 'done', true, [], [['4.200000', 32]]],
        ['Fix the empty search state', 'opus', 'bug', 'done', true, [], [['3.800000', 28]]],
        ['Show the run cost on a card', 'opus', 'feature', 'done', true, ['conflict'], [['3.100000', 26], ['2.000000', 14]]],
        ['Retry a lost webhook', 'opus', 'bug', 'done', true, [], [['4.600000', 35]]],
        ['Sort the inbox by due date', 'opus', 'feature', 'done', true, [], [['3.900000', 30]]],
        ['Link a document to a card', 'opus', 'feature', 'done', true, ['agent-review'], [['4.400000', 38]]],
        ['Archive a finished epic', 'opus', 'feature', 'in-progress', false, [], [['4.000000', 31]]],
        ['Paginate the audit log', 'sonnet', 'feature', 'done', true, [], [['1.600000', 41]]],
        ['Fix a stale badge count', 'sonnet', 'bug', 'done', true, ['checks-failed'], [['1.200000', 33], ['0.700000', 18]]],
        ['Rename a board column', 'sonnet', 'feature', 'done', true, [], [['1.400000', 37]]],
        ['Mute a noisy bridge', 'sonnet', 'feature', 'done', true, ['conflict', 'checks-failed'], [['1.500000', 44], ['0.600000', 21]]],
        ['Show the merge time on a card', 'sonnet', 'feature', 'done', true, [], [['1.700000', 39]]],
        ['Fix the dark mode chart colours', 'sonnet', 'bug', 'done', false, [], [['1.500000', 52]]],
        ['Filter the runs by bridge', 'sonnet', 'feature', 'in-progress', false, [], [['1.800000', 47]]],
        ['Import cards from a CSV file', 'sonnet', 'feature', 'next', false, [], []],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private ExperimentDefinitionRepository $experimentDefinitions,
        private ExperimentPinRepository $experimentPins,
        private WorkerRunRepository $workerRuns,
        private WorkRequestRepository $workRequests,
        private ForgePullRequestRepository $forgePullRequests,
        private DevRunCardLinks $runCardLinks,
        private DevRunUsage $runUsage,
    ) {
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
        foreach (self::EXPERIMENTS as [$experiment, $headline, $metrics]) {
            $this->seedExperiment($context, $project, $experiment, $headline, $metrics);
        }
    }

    /**
     * With $metrics, the experiment lists those metrics, and each merged card gets a pull request that opened and merged.
     * A later seed looks up each record on its own and writes only a missing one. A pull request
     * link is found by its URL. A merge event has no fixed id, so it comes only with a new card.
     *
     * @param array<string, mixed>|null $headline
     * @param list<string>|null         $metrics
     */
    private function seedExperiment(SeedContext $context, Project $project, string $experiment, ?array $headline, ?array $metrics): void
    {
        $definitionId = DevSeedId::of('bridge/experiment/'.$experiment);
        if (!$this->experimentDefinitions->find($definitionId) instanceof ExperimentDefinition) {
            if ($this->experimentDefinitions->findOneBy(['project' => $project, 'experiment' => $experiment]) instanceof ExperimentDefinition) {
                return;
            }

            $definition = new ExperimentDefinition($project, $experiment, [['name' => 'opus', 'weight' => 1], ['name' => 'sonnet', 'weight' => 1]]);
            $definition->metrics = $metrics ?? ['cost', 'merge-rate', 'duration', 'input-tokens', 'tool-time'];
            $definition->headline = $headline;
            $context->ids->assign($definition, $definitionId);
            $this->em->persist($definition);
        }

        $columns = [];
        foreach ($this->boardColumns->findForProject($project) as $column) {
            $columns[$column->slug] = $column;
        }
        $at = $context->now->modify('-20 days');
        foreach (self::CARDS as [$title, $variant, $type, $slug, $merged, $fixReasons, $runs]) {
            $column = $columns[$slug] ?? throw new \LogicException(\sprintf('The project has a %s column.', $slug));
            $key = $experiment.'/'.Slug::fromName($title);
            $openedAt = $at->modify('+20 hours');
            $stored = $this->cards->find(DevSeedId::of('board/card/'.$key));
            $card = $stored ?? $this->card($context, $project, $column, $title, $type, $this->cards->nextNumber($project), $at->modify('-10 days'), DevSeedId::of('board/card/'.$key));
            $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');
            if (!$this->experimentPins->findOneBy(['project' => $project, 'cardId' => $cardId, 'experiment' => $experiment]) instanceof ExperimentPin) {
                $this->em->persist(new ExperimentPin($project, $cardId, $experiment, $variant, $at, $at));
            }

            foreach ($runs as $position => [$cost, $minutes]) {
                $at = $at->modify('+20 hours');
                $runId = DevSeedId::of(\sprintf('bridge/run/%s/%d', $key, $position));
                $run = $this->workerRuns->find($runId) ?? $this->run($context, $project, $card, $experiment, $variant, $at, $minutes, $runId);
                $tokens = (int) round((float) $cost * 40_000);
                $this->runUsage->record($context, $run, self::MODELS[$variant], $tokens, intdiv($tokens, 20), $tokens * 6, intdiv($tokens, 3), $cost);
            }
            foreach ($fixReasons as $reason) {
                $requestId = DevSeedId::of(\sprintf('bridge/work-request/%s/%s', $key, $reason));
                if (!$this->workRequests->find($requestId) instanceof WorkRequest) {
                    $this->fixRequest($context, $project, $card, $reason, $at->modify('+1 hour'), $requestId);
                }
            }
            if ($merged) {
                if (null !== $metrics) {
                    $this->pullRequest($project, $card, $openedAt, $at->modify('+2 hours'));
                }
                if (!$stored instanceof Card) {
                    $this->cardEvents->record($card, CardEventKind::Moved, Actor::System, null, ['from' => [], 'to' => [], 'cause' => CardEventCause::workflowRule('merged', 'merged')->detail()], $at->modify('+2 hours'));
                }
            }
            if ($stored instanceof Card) {
                continue;
            }
            $this->runCardLinks->relink($project, $cardId, $card->number);
            if ($column->terminal) {
                $card->completedAt = $at->modify('+2 hours');
            }
        }
        $this->em->flush();
    }

    private function card(SeedContext $context, Project $project, BoardColumn $column, string $title, string $type, int $number, \DateTimeImmutable $createdAt, Uuid $id): Card
    {
        $card = new Card(project: $project, column: $column, title: $title, body: '', number: $number, type: $type, createdAt: $createdAt);
        $context->ids->assign($card, $id);
        $this->em->persist($card);
        $this->em->flush();
        $this->cardEvents->record($card, CardEventKind::Created, Actor::Agent, null, [], $createdAt);

        return $card;
    }

    /** Writes the link and the state that are missing, each on its own, and keeps a stored state. */
    private function pullRequest(Project $project, Card $card, \DateTimeImmutable $openedAt, \DateTimeImmutable $mergedAt): void
    {
        $url = 'https://github.com/example/experiment/pull/'.$card->number;
        if (!$card->pullRequests->exists(static fn (int $key, CardPullRequest $link): bool => $url === $link->url)) {
            $link = new CardPullRequest($card, $url, Forge::GitHub, 'example/experiment', $card->number);
            $this->em->persist($link);
            $card->pullRequests->add($link);
        }

        if ($this->forgePullRequests->findOneBy(['project' => $project, 'forge' => 'github', 'repository' => 'example/experiment', 'number' => $card->number]) instanceof ForgePullRequest) {
            return;
        }

        $state = new ForgePullRequest($project, 'github', 'example/experiment', $card->number);
        $state->openedAt = $openedAt;
        $state->mergedAt = $mergedAt;
        $this->em->persist($state);
    }

    /** A settled fix request, which the experiment counts as a fix round of the reason. */
    private function fixRequest(SeedContext $context, Project $project, Card $card, string $reason, \DateTimeImmutable $at, Uuid $id): void
    {
        $request = new WorkRequest(
            project: $project,
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A stored card has an id.'),
            cardNumber: $card->number,
            kind: 'fix',
            capability: null,
            ruleId: 'fix-on-failure',
            createdAt: $at,
        );
        $request->role = 'fix';
        $request->context = new WorkRequestContext(reason: $reason);
        $request->state = WorkRequestState::Done;
        $request->settledAt = $at->modify('+10 minutes');
        $context->ids->assign($request, $id);
        $this->em->persist($request);
    }

    /** WorkerRunFactListener writes the fact row of the run on flush. */
    private function run(SeedContext $context, Project $project, Card $card, string $experiment, string $variant, \DateTimeImmutable $endedAt, int $minutes, Uuid $id): WorkerRun
    {
        $run = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v4(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A stored card has an id.'),
            cardNumber: $card->number,
            workKind: 'implement',
            state: WorkerRunState::Succeeded,
            runKey: Uuid::v7(),
            startedAt: $endedAt->modify(\sprintf('-%d minutes', $minutes)),
            endedAt: $endedAt,
            exitCode: 0,
            hasResult: true,
            output: 'The pull request is open.',
            receivedAt: $endedAt,
        );
        $run->experiment = $experiment;
        $run->variant = $variant;
        $run->requestedModel = self::MODELS[$variant];
        $run->recordHarness('claude-code', 'claude', null, null);
        $run->usageSource = WorkerRunUsageSource::Reported;
        $context->ids->assign($run, $id);
        $this->em->persist($run);

        return $run;
    }
}
