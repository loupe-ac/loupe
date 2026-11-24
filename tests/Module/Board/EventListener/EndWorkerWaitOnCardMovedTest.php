<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** Driven through a real move, so the listener runs where UpdateCardHandler dispatches CardMoved. */
final class EndWorkerWaitOnCardMovedTest extends KernelTestCase
{
    use ResolveFeedbackScenario;

    /** @return iterable<string, array{string, WorkerWaitEndReason}> */
    public static function moves(): iterable
    {
        yield 'to an open column' => ['in-progress', WorkerWaitEndReason::CardMoved];
        yield 'to a terminal column' => ['done', WorkerWaitEndReason::CardFinished];
    }

    #[DataProvider('moves')]
    public function test_a_move_ends_the_wait_and_stops_the_parked_run(string $slug, WorkerWaitEndReason $reason): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $project = $this->feedbackProject('wait-move-'.$slug);
        $card = $this->cardIn($project, 'backlog');
        $this->em->flush();
        $cardId = $card->id ?? throw new \LogicException('Seeded.');
        $bridgeId = Uuid::v4();
        $run = new WorkerRun($project, $bridgeId, WorkSubject::CARD, $cardId, $card->number, 'implementation', WorkerRunState::Waiting, Uuid::v4());
        $this->em->persist($run);
        $this->em->flush();
        $opened = new \DateTimeImmutable('-5 minutes');
        $wait = new WorkerWait($project, $cardId, $run->id ?? throw new \LogicException('Seeded.'), $run->id, $bridgeId, Uuid::v4(), [], $opened, $opened->modify('+1 hour'));
        $this->em->persist($wait);
        $this->em->flush();

        $handler = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $handler);
        $handler(new UpdateCardCommand($card, Actor::Human, column: $this->column($project, $slug)));

        $this->em->clear();
        self::assertSame($reason, $this->em->find(WorkerWait::class, $wait->id)?->endReason);
        self::assertSame(WorkerRunState::Stopped, $this->em->find(WorkerRun::class, $run->id)?->state);
        self::assertSame(1, $this->waitEndedRows($card));
    }

    public function test_a_move_inside_the_same_column_leaves_the_wait_open(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $project = $this->feedbackProject('wait-rank');
        $card = $this->cardIn($project, 'backlog');
        $this->em->flush();
        $cardId = $card->id ?? throw new \LogicException('Seeded.');
        $bridgeId = Uuid::v4();
        $run = new WorkerRun($project, $bridgeId, WorkSubject::CARD, $cardId, $card->number, 'implementation', WorkerRunState::Waiting, Uuid::v4());
        $this->em->persist($run);
        $this->em->flush();
        $opened = new \DateTimeImmutable('-5 minutes');
        $wait = new WorkerWait($project, $cardId, $run->id ?? throw new \LogicException('Seeded.'), $run->id, $bridgeId, Uuid::v4(), [], $opened, $opened->modify('+1 hour'));
        $this->em->persist($wait);
        $this->em->flush();

        $handler = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $handler);
        $handler(new UpdateCardCommand($card, Actor::Human, column: $this->column($project, 'backlog'), position: 0));

        $this->em->clear();
        self::assertNull($this->em->find(WorkerWait::class, $wait->id)?->endReason);
        self::assertSame(WorkerRunState::Waiting, $this->em->find(WorkerRun::class, $run->id)?->state);
        self::assertSame(0, $this->waitEndedRows($card));
    }

    private function waitEndedRows(Card $card): int
    {
        return self::getContainer()->get(\App\Module\Board\Repository\CardEventRepository::class)->count(['card' => $card->id, 'kind' => CardEventKind::WaitEnded]);
    }
}
