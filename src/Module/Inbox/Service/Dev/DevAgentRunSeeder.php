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
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\Dev\DevBridgeSeeder;
use App\Module\Bridge\Service\Dev\DevRunCardLinks;
use App\Module\Bridge\Service\Dev\DevRunUsage;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Runs in the states that a person meets on the runs page and the card page:
 * live, waiting, finished, failed and stopped. They sit on one card in the
 * implementation column. It lives here because only Board, Inbox, Readiness and
 * AgentReview may import Board.
 */
#[When('dev')]
#[When('test')]
final readonly class DevAgentRunSeeder implements DevSeederInterface
{
    public const string KEY = 'agent-runs';
    public const string CARD_TITLE = 'Retry a failed checkout payment';

    /**
     * Minutes before now that the run started, and for how long it ran. Null duration: the run is still open.
     *
     * @var list<array{key: string, state: WorkerRunState, workKind: string, startedAgo: int, minutes: ?int, exitCode: ?int, failure: ?string, output: string}>
     */
    private const array RUNS = [
        ['key' => 'running', 'state' => WorkerRunState::Running, 'workKind' => 'implement', 'startedAgo' => 14, 'minutes' => null, 'exitCode' => null, 'failure' => null, 'output' => 'Reading the checkout handler.'],
        ['key' => 'waiting', 'state' => WorkerRunState::WaitingForPerson, 'workKind' => 'fix-round', 'startedAgo' => 55, 'minutes' => null, 'exitCode' => null, 'failure' => null, 'output' => 'The run asked the owner which retry limit to use.'],
        ['key' => 'succeeded', 'state' => WorkerRunState::Succeeded, 'workKind' => 'review', 'startedAgo' => 180, 'minutes' => 11, 'exitCode' => 0, 'failure' => null, 'output' => 'The review found no problem.'],
        ['key' => 'failed', 'state' => WorkerRunState::Failed, 'workKind' => 'implement', 'startedAgo' => 400, 'minutes' => 7, 'exitCode' => 1, 'failure' => 'The gate failed: phpstan reported two errors.', 'output' => 'The gate failed.'],
        ['key' => 'usage-limit', 'state' => WorkerRunState::UsageLimit, 'workKind' => 'implement', 'startedAgo' => 520, 'minutes' => 3, 'exitCode' => 1, 'failure' => 'The account reached its usage limit.', 'output' => 'Usage limit reached.'],
        ['key' => 'stopped', 'state' => WorkerRunState::Stopped, 'workKind' => 'fix-round', 'startedAgo' => 700, 'minutes' => 5, 'exitCode' => null, 'failure' => null, 'output' => 'A person stopped the run.'],
        ['key' => 'timed-out', 'state' => WorkerRunState::TimedOut, 'workKind' => 'implement', 'startedAgo' => 900, 'minutes' => 60, 'exitCode' => null, 'failure' => 'The run passed its time limit.', 'output' => ''],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private WorkerRunRepository $workerRuns,
        private DevRunCardLinks $runCardLinks,
        private DevRunUsage $runUsage,
    ) {
    }

    public static function cardId(): Uuid
    {
        return DevSeedId::of('board/card/demo-agent-runs');
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY, DevBridgeSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::devProjectId())
            ?? throw new \LogicException('The dev project does not exist.');
        $card = $this->card($context, $project);
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');
        $this->runCardLinks->relink($project, $cardId, $card->number);

        foreach (self::RUNS as $row) {
            $id = DevSeedId::of('bridge/run/agent-runs/'.$row['key']);
            $stored = $this->workerRuns->find($id);
            if ($stored instanceof WorkerRun) {
                if (null === $row['minutes'] && null === $stored->endedAt) {
                    $this->keepOpen($context, $stored, $row['state'], $row['startedAgo']);
                }
                $this->recordUsage($context, $stored, $row['state']);

                continue;
            }

            $startedAt = $context->at($row['startedAgo'] * 60);
            $endedAt = null === $row['minutes'] ? null : $startedAt->modify(\sprintf('+%d minutes', $row['minutes']));
            $run = new WorkerRun(
                project: $project,
                bridgeId: Uuid::fromString(DevBridgeSeeder::MAIN_BRIDGE),
                subjectType: WorkSubject::CARD,
                subjectId: $cardId,
                cardNumber: $card->number,
                workKind: $row['workKind'],
                state: $row['state'],
                runKey: DevSeedId::of('bridge/run-key/agent-runs/'.$row['key']),
                sessionId: DevSeedId::of('bridge/session/agent-runs/'.$row['key']),
                startedAt: $startedAt,
                endedAt: $endedAt,
                exitCode: $row['exitCode'],
                hasResult: null === $endedAt ? null : WorkerRunState::Succeeded === $row['state'],
                failureReason: $row['failure'],
                output: $row['output'],
                receivedAt: $endedAt ?? $startedAt,
            );
            $run->recordHarness('claude-code', 'claude-main', 'claude-sonnet-5-5', null);
            $run->usageSource = WorkerRunUsageSource::Reported;
            $context->ids->assign($run, $id);
            $this->em->persist($run);
            $this->recordUsage($context, $run, $row['state']);
        }
        $this->em->flush();
    }

    /** No bridge reports these runs, so the sweep for a quiet bridge times out each one that it reads as open. */
    private function keepOpen(SeedContext $context, WorkerRun $run, WorkerRunState $state, int $startedAgo): void
    {
        $run->startedAt = $context->at($startedAgo * 60);
        if (WorkerRunState::TimedOut === $run->state) {
            $run->moveTo($state);
            $this->em->persist(new WorkerRunStateChange($run, $state, $context->now, $context->now));
        }
    }

    private function recordUsage(SeedContext $context, WorkerRun $run, WorkerRunState $state): void
    {
        if (null === $run->endedAt || WorkerRunState::TimedOut === $state) {
            return;
        }
        $this->runUsage->record($context, $run, 'claude-sonnet-5-5', 42_000, 6_500, 180_000, 21_000, '0.8400');
    }

    private function card(SeedContext $context, Project $project): Card
    {
        $card = $this->cards->find(self::cardId());
        if ($card instanceof Card) {
            return $card;
        }

        $columns = [];
        foreach ($this->boardColumns->findForProject($project) as $column) {
            $columns[$column->slug] = $column;
        }
        $column = $columns['implementation'] ?? $columns['in-progress'] ?? $columns['backlog'] ?? throw new \LogicException('The project has no board column.');
        \assert($column instanceof BoardColumn);

        $createdAt = $context->at(86_400);
        $card = new Card(project: $project, column: $column, title: self::CARD_TITLE, body: '', number: $this->cards->nextNumber($project), type: 'feature', createdAt: $createdAt);
        $context->ids->assign($card, self::cardId());
        $this->em->persist($card);
        $this->em->flush();
        $this->cardEvents->record($card, CardEventKind::Created, Actor::Agent, null, [], $createdAt);
        $this->em->flush();

        return $card;
    }
}
