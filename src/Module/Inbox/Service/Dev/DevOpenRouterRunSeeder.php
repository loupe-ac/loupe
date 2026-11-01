<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunBilledCost;
use App\Module\Bridge\Repository\WorkerRunBilledCostRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\Dev\DevBridgeSeeder;
use App\Module\Bridge\Service\Dev\DevRunCardLinks;
use App\Module\Bridge\Service\Dev\DevRunUsage;
use App\Module\Bridge\ValueObject\BilledCostReason;
use App\Module\Bridge\ValueObject\BilledCostState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevOpenRouterProjectSeeder;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Four OpenRouter runs on one done card of the OpenRouter project, so the run
 * drawer and the usage total of the card hold their billed cost. Two runs are
 * billed and two have an unknown cost. None is pending, because only a run
 * report starts a cost read, so a seeded pending row would stay pending. The
 * seed makes no call to OpenRouter.
 */
#[When('dev')]
#[When('test')]
final readonly class DevOpenRouterRunSeeder implements DevSeederInterface
{
    public const string KEY = 'openrouter-runs';
    public const string CARD_TITLE = 'Bill a worker run through OpenRouter';

    private const string MODEL = 'anthropic/claude-sonnet-5-5';

    /** @var list<array{key: string, daysAgo: int, state: BilledCostState, reason: ?BilledCostReason, cost: ?string, attempts: int}> */
    private const array RUNS = [
        ['key' => 'billed-1', 'daysAgo' => 6, 'state' => BilledCostState::Billed, 'reason' => null, 'cost' => '1.482300', 'attempts' => 2],
        ['key' => 'billed-2', 'daysAgo' => 3, 'state' => BilledCostState::Billed, 'reason' => null, 'cost' => '0.731900', 'attempts' => 1],
        ['key' => 'no-key', 'daysAgo' => 2, 'state' => BilledCostState::Unknown, 'reason' => BilledCostReason::NoKey, 'cost' => null, 'attempts' => 1],
        ['key' => 'no-cost', 'daysAgo' => 1, 'state' => BilledCostState::Unknown, 'reason' => BilledCostReason::NoCostFound, 'cost' => null, 'attempts' => 5],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private WorkerRunRepository $workerRuns,
        private WorkerRunBilledCostRepository $workerRunBilledCosts,
        private DevRunCardLinks $runCardLinks,
        private DevRunUsage $runUsage,
    ) {
    }

    public static function cardId(): Uuid
    {
        return DevSeedId::of('board/card/demo-openrouter-runs');
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevOpenRouterProjectSeeder::KEY, DevBridgeSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->projects->find(DevOpenRouterProjectSeeder::projectId())
            ?? throw new \LogicException('The OpenRouter project does not exist.');
        $card = $this->card($context, $project);
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');

        foreach (self::RUNS as $row) {
            $id = DevSeedId::of('bridge/run/openrouter/'.$row['key']);
            $run = $this->workerRuns->find($id) ?? $this->run($context, $project, $card, $id, $row['key'], $row['daysAgo']);
            $this->runUsage->record($context, $run, self::MODEL, 52_000, 7_800, 210_000, 24_000, null, true);
            $this->recordBilledCost($project, $run, $row);
        }
        $this->em->flush();
        $this->runCardLinks->relink($project, $cardId, $card->number);
    }

    /** @param array{key: string, daysAgo: int, state: BilledCostState, reason: ?BilledCostReason, cost: ?string, attempts: int} $row */
    private function recordBilledCost(Project $project, WorkerRun $run, array $row): void
    {
        $runId = $run->id ?? throw new \LogicException('A stored run has an id.');
        if ($this->workerRunBilledCosts->find($runId) instanceof WorkerRunBilledCost) {
            return;
        }

        $billed = new WorkerRunBilledCost(
            runId: $runId,
            runKey: $run->runKey ?? throw new \LogicException('A seeded run has a run key.'),
            project: $project,
            subjectType: $run->subjectType,
            subjectId: $run->subjectId,
            runStartedAt: $run->startedAt ?? $run->receivedAt,
        );
        $billed->state = $row['state'];
        $billed->reason = $row['reason'];
        $billed->costUsd = $row['cost'];
        $billed->attempts = $row['attempts'];
        $billed->updatedAt = $run->endedAt ?? $run->receivedAt;
        $this->em->persist($billed);
    }

    private function run(SeedContext $context, Project $project, Card $card, Uuid $id, string $key, int $daysAgo): WorkerRun
    {
        $endedAt = $context->now->modify(\sprintf('-%d days', $daysAgo));
        $run = new WorkerRun(
            project: $project,
            bridgeId: Uuid::fromString(DevBridgeSeeder::HISTORY_BRIDGE),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A stored card has an id.'),
            cardNumber: $card->number,
            workKind: 'implement',
            state: WorkerRunState::Succeeded,
            runKey: DevSeedId::of('bridge/run-key/openrouter/'.$key),
            sessionId: DevSeedId::of('bridge/session/openrouter/'.$key),
            startedAt: $endedAt->modify('-14 minutes'),
            endedAt: $endedAt,
            exitCode: 0,
            hasResult: true,
            output: 'The pull request is open.',
            receivedAt: $endedAt,
        );
        $run->recordHarness('claude-code', 'openrouter', self::MODEL, null);
        $run->usageSource = WorkerRunUsageSource::Reported;
        $context->ids->assign($run, $id);
        $this->em->persist($run);

        return $run;
    }

    private function card(SeedContext $context, Project $project): Card
    {
        $card = $this->cards->find(self::cardId());
        if ($card instanceof Card) {
            return $card;
        }

        $column = array_find($this->boardColumns->findForProject($project), static fn (BoardColumn $column): bool => 'done' === $column->slug)
            ?? throw new \LogicException('The project has a done column.');
        $createdAt = $context->now->modify('-7 days');
        $card = new Card(project: $project, column: $column, title: self::CARD_TITLE, body: '', number: $this->cards->nextNumber($project), type: 'feature', createdAt: $createdAt);
        $context->ids->assign($card, self::cardId());
        $card->completedAt = $context->now->modify('-1 day');
        $this->em->persist($card);
        $this->em->flush();
        $this->cardEvents->record($card, CardEventKind::Created, Actor::Agent, null, [], $createdAt);
        $this->em->flush();

        return $card;
    }
}
