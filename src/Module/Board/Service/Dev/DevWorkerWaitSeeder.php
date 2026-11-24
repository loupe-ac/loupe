<?php

declare(strict_types=1);

namespace App\Module\Board\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\Service\Dev\DevBridgeSeeder;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Workflow\Contract\MarkerKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes two cards in the Implementation column of the Lifecycle project. The first one has a worker that waits for the
 * checks of a pull request. Its wait opens twelve minutes before each seed and runs for sixty, so the card always shows an
 * open wait. The second one has a wait that ended while its bridge is offline.
 * The marker is stored as the engine stores it, so the card page shows the wait with no evaluation.
 */
#[When('dev')]
#[When('test')]
final readonly class DevWorkerWaitSeeder implements DevSeederInterface
{
    public const string KEY = 'board-worker-wait';

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private WorkerRunRepository $workerRuns,
        private WorkerWaitRepository $workerWaits,
    ) {
    }

    public static function cardId(): Uuid
    {
        return DevSeedId::of('board/card/worker-wait');
    }

    /** The card whose wait ended while its bridge is offline. */
    public static function strandedCardId(): Uuid
    {
        return DevSeedId::of('board/card/worker-wait-stranded');
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
        $this->seedCard($context, false);
        $this->seedCard($context, true);
    }

    private function seedCard(SeedContext $context, bool $stranded): void
    {
        $slug = $stranded ? 'worker-wait-stranded' : 'worker-wait';
        $cardId = $stranded ? self::strandedCardId() : self::cardId();
        $project = $this->projects->find(DevProjectSeeder::lifecycleProjectId())
            ?? throw new \LogicException('The lifecycle project does not exist.');
        $card = $this->cards->find($cardId);
        if (!$card instanceof Card) {
            $column = array_find($this->boardColumns->findForProject($project), static fn (BoardColumn $column): bool => 'implementation' === $column->slug)
                ?? throw new \LogicException('The lifecycle project has no implementation column.');
            $card = new Card(
                project: $project,
                column: $column,
                title: $stranded ? 'A card whose wait ended while its bridge is offline' : 'A card whose worker waits for the checks',
                body: $stranded ? 'Shows the stuck status box of a wait that its offline bridge cannot resume, with its two controls.' : 'Shows the status box of a worker that waits, with its time limit and its three controls.',
                number: $this->cards->nextNumber($project),
                type: 'feature',
                createdAt: $context->at(3_600),
            );
            $context->ids->assign($card, $cardId);
            $this->em->persist($card);
            $this->em->flush();
        }
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');

        // No bridge row has this id, so no heartbeat reaches the stranded wait.
        $bridgeId = $stranded ? DevSeedId::of('bridge/offline-wait') : Uuid::fromString(DevBridgeSeeder::MAIN_BRIDGE);
        $opened = $context->at(12 * 60);
        $runId = DevSeedId::of('bridge/run/'.$slug);
        $run = $this->workerRuns->find($runId);
        if (!$run instanceof WorkerRun) {
            $run = new WorkerRun(
                project: $project,
                bridgeId: $bridgeId,
                subjectType: WorkSubject::CARD,
                subjectId: $cardId,
                cardNumber: $card->number,
                workKind: 'implementation',
                state: WorkerRunState::Waiting,
                runKey: DevSeedId::of('bridge/run-key/'.$slug),
                sessionId: DevSeedId::of('bridge/session/'.$slug),
                startedAt: $opened->modify('-20 minutes'),
                endedAt: $opened,
                exitCode: 0,
                hasResult: true,
                output: 'The worker pushed the pull request and waits for its checks.',
                receivedAt: $opened,
            );
            $context->ids->assign($run, $runId);
            $this->em->persist($run);
        }

        $waitId = DevSeedId::of('bridge/wait/'.$slug);
        $stored = $this->workerWaits->find($waitId);
        if ($stored instanceof WorkerWait) {
            $this->em->remove($stored);
            $this->em->flush();
        }
        $wait = new WorkerWait(
            project: $project,
            cardId: $cardId,
            runId: $runId,
            chainRootRunId: $runId,
            bridgeId: $bridgeId,
            sessionId: DevSeedId::of('bridge/session/'.$slug),
            conditions: [],
            openedAt: $opened,
            limitAt: $opened->modify('+60 minutes'),
        );
        $context->ids->assign($wait, $waitId);
        $this->em->persist($wait);

        if ($stranded) {
            $wait->end(WorkerWaitEndReason::Condition, $opened->modify('+5 minutes'), []);
        }
        $card->markers = [$stranded ? [
            'key' => 'wait-bridge-offline',
            'kind' => MarkerKind::Stuck->value,
            'label' => 'marker.wait_bridge_offline',
            'remedy' => 'marker.wait_bridge_offline.remedy',
            'params' => [],
            'since' => $opened->modify('+5 minutes')->format(\DATE_ATOM),
        ] : [
            'key' => 'worker-waiting',
            'kind' => MarkerKind::Waiting->value,
            'label' => 'marker.worker_waiting',
            'remedy' => 'marker.worker_waiting.remedy',
            'params' => ['%conditions%' => 'the checks of pr:loupe#1801 to pass or fail'],
            'since' => $opened->format(\DATE_ATOM),
        ]];
        $card->inboxMarkers = [];
        $card->markersDueAt = $stranded ? null : $opened->modify('+60 minutes');
        $this->em->flush();
    }
}
